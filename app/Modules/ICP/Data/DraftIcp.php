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
     * Map the validated AI output to a draft, re-validating it against the save path's own rules.
     *
     * Returns a refusal rather than throwing when the draft cannot be a profile: the member asked a
     * reasonable question and got an unusable answer, which is a thing to tell them about and not a
     * 500. The failure is logged by the Action (counts only, never the prose).
     *
     * @param  array<string, mixed>  $output
     */
    public static function fromAi(array $output): self
    {
        if (($output['is_viable'] ?? false) !== true) {
            return self::refused(
                is_string($output['reason'] ?? null) && $output['reason'] !== ''
                    ? $output['reason']
                    : 'That does not describe the customers you are looking for. Say what kind of business they are and what they need.',
            );
        }

        /** @var array<string, mixed> $profile */
        $profile = is_array($output['profile'] ?? null) ? $output['profile'] : [];

        $validator = Validator::make($profile, IcpData::rules());

        if ($validator->fails()) {
            return self::refused('Alima drafted something it could not turn into a profile. Try describing your customers in one or two sentences.');
        }

        return new self(
            viable: true,
            reason: null,
            summary: is_string($output['summary'] ?? null)
                ? Str::limit(Str::squish($output['summary']), self::MAX_SUMMARY, '')
                : null,
            // Through `fromValidated`, so a drafted criterion is trimmed, weighted and
            // exclusion-normalised by exactly the code that does it for a hand-written one.
            profile: IcpData::fromValidated($validator->validated()),
        );
    }
}
