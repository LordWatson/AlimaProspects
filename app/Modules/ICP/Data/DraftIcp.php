<?php

declare(strict_types=1);

namespace App\Modules\ICP\Data;

use App\Modules\ICP\Actions\CreateIcpAction;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Spatie\LaravelData\Data;

/**
 * What the AI composer drafted from a member's description of who they sell to — **not** a saved
 * profile.
 *
 * The same arrangement the Signals builder uses (ADR 0020), and for the same reason: the AI drafts,
 * the member owns. Nothing is persisted until somebody has read the draft, changed what they like
 * and pressed save, which is the ordinary {@see CreateIcpAction} path. A
 * profile decides how every lead in the account is graded and what discovery searches for, so a
 * prompt that wrote one directly would be a prompt with write access to the team's grading.
 *
 * **A draft can only contain what a profile can hold.** The AI's output is re-validated against
 * {@see IcpData::rules()} — the rules the form posts through — rather than trusted: a model that
 * returns a criterion type nobody defined, a 900-character value or a weight of 400 produces a
 * refusal here instead of a surprise on save.
 *
 * **`viable: false` is a first-class answer.** "Get me the mobile numbers of rich people in Dubai"
 * is not an ideal customer profile, and a composer that produced plausible criteria for it would be
 * worse than one that refuses: it would grade the whole account against nonsense. The reason is the
 * member's to read.
 */
final class DraftIcp extends Data
{
    public const int MAX_REASON = 300;

    public const int MAX_SUMMARY = 300;

    public function __construct(
        public readonly bool $viable,
        /** Why not, when it is not. Null when it is. */
        public readonly ?string $reason,
        /** What the composer understood the member to sell, and to whom, in its own words. */
        public readonly ?string $summary,
        public readonly ?IcpData $profile,
    ) {}

    /**
     * A refusal, with the reason the member asked for.
     */
    public static function refused(string $reason): self
    {
        return new self(
            viable: false,
            reason: Str::limit(Str::squish($reason), self::MAX_REASON, ''),
            summary: null,
            profile: null,
        );
    }

    /**
     * Map the AI output to a draft, re-validating it against the save path's own rules.
     *
     * **A bad row is dropped; it does not sink the draft.** The first version validated the whole
     * profile and refused it whole, which meant one criterion the save path would not take — an
     * empty value, a weight of nothing — threw away eight good ones and told the member their
     * perfectly clear description could not be understood. Nobody can act on that, and it is the
     * wrong trade twice over: a draft is a *suggestion on a form the member is about to edit*, so
     * the worst a questionable row can do is be deleted, while refusing costs a reasoning-tier call
     * and leaves them where they started.
     *
     * So each criterion is validated **on its own** and the ones that pass are kept. Strictness is
     * unchanged in the direction that matters: nothing reaches the editor that `SaveIcpRequest`
     * would reject, because the rules are the same rules. What changed is the response to a
     * violation — repair, not refusal.
     *
     * A refusal is kept for the two cases where there is genuinely nothing to show: no name, or no
     * criterion survived. `$dropped` is handed back so the Action can log the count (never the
     * prose) — a model quietly losing half its rows is a prompt problem, and the only way to notice
     * it is to count.
     *
     * @param  array<string, mixed>  $output
     */
    public static function fromAi(array $output, int &$dropped = 0): self
    {
        $dropped = 0;

        if (($output['is_viable'] ?? false) !== true) {
            return self::refused(
                is_string($output['reason'] ?? null) && $output['reason'] !== ''
                    ? $output['reason']
                    : 'That does not describe the customers you are looking for. Say what kind of business they are and what they need.',
            );
        }

        /** @var array<string, mixed> $profile */
        $profile = is_array($output['profile'] ?? null) ? $output['profile'] : [];

        /** @var list<mixed> $rows */
        $rows = is_array($profile['criteria'] ?? null) ? array_values($profile['criteria']) : [];

        $criteria = [];

        foreach ($rows as $row) {
            // One row, the row's own rules. `criteria.*` keys unwrapped to a single row, so the one
            // definition in `IcpData::rules()` stays the only one.
            $validator = Validator::make(is_array($row) ? $row : [], [
                'type' => IcpData::rules()['criteria.*.type'],
                'value' => IcpData::rules()['criteria.*.value'],
                'weight' => IcpData::rules()['criteria.*.weight'],
            ]);

            if ($validator->fails()) {
                $dropped++;

                continue;
            }

            $criteria[] = $validator->validated();
        }

        $shell = Validator::make([
            'name' => $profile['name'] ?? null,
            'description' => $profile['description'] ?? null,
            'criteria' => $criteria,
        ], IcpData::rules());

        if ($shell->fails() || $criteria === []) {
            return self::refused('Alima drafted something it could not turn into a profile. Try describing your customers in one or two sentences.');
        }

        /** @var array<string, mixed> $validated */
        $validated = $validator->validated();

        // Viable, but no criteria followed — a profile with nothing in it grades nothing and
        // searches for nothing, so it is a refusal with a nudge rather than an empty editor. The
        // schema no longer lets a viable answer omit the profile, but it can still come back thin.
        if (($validated['criteria'] ?? []) === []) {
            return self::refused('Alima couldn’t pull specific criteria from that. Add a detail or two — the kind of business, roughly how big, or where — and try again.');
        }

        return new self(
            viable: true,
            reason: null,
            summary: is_string($output['summary'] ?? null)
                ? Str::limit(Str::squish($output['summary']), self::MAX_SUMMARY, '')
                : null,
            // Through `fromValidated`, so a drafted criterion is trimmed, weighted and
            // exclusion-normalised by exactly the code that does it for a hand-written one.
            profile: IcpData::fromValidated($validated),
        );
    }
}
