<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Actions;

use App\Models\User;
use App\Modules\Compliance\Data\LiaRecordData;
use App\Modules\Compliance\Enums\LiaStatus;
use App\Modules\Compliance\Exceptions\ComplianceException;
use App\Modules\Compliance\Models\LiaRecord;
use App\Modules\Compliance\Services\LegitimateInterestGate;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Support\TeamContext;
use Illuminate\Support\Facades\DB;

/**
 * Saves, signs off, withdraws or re-affirms a team's legitimate-interest assessment
 * (docs/compliance.md §2). One row per (team, market), so this upserts.
 *
 * The decisions worth knowing:
 *
 * - **Saving and signing are separate.** A draft is work in progress and does not open the discovery
 *   gate. Signing stamps `completed_by`, `completed_at` and the review date, and is refused unless
 *   all three tests are actually filled in — a lawful basis that can be affirmed by pressing a
 *   button over three empty boxes records nothing.
 * - **`version` is bumped on each signing, not on each save.** It exists so an audit entry can say
 *   which assessment was in force when a discovery run happened; a counter that moved every time
 *   somebody fixed a typo would say nothing.
 * - **A withdrawal is recorded, never deleted.** "There was a basis, and it ran until this date" is
 *   itself the accountability record (§3.8). Signing again clears the withdrawal and starts a new
 *   version.
 * - **Audited, with the sections left out.** The audit entry carries the market, the status and the
 *   version — not the text. The assessment itself is in the table and in the team's export; copying
 *   it into an append-only log on every edit would be noise, not accountability.
 */
final class SaveLiaRecordAction
{
    public function __construct(
        private readonly TeamContext $teamContext,
        private readonly LegitimateInterestGate $gate,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws ComplianceException when signing an assessment that isn't filled in
     */
    public function handle(Team $team, User $member, LiaRecordData $data): LiaRecord
    {
        return $this->teamContext->run($team, function () use ($team, $member, $data): LiaRecord {
            $record = DB::transaction(function () use ($team, $member, $data): LiaRecord {
                $record = LiaRecord::query()->firstOrNew([
                    'team_id' => $team->getKey(),
                    'market' => $data->market->value,
                ]);

                $record->fill([
                    'team_id' => $team->getKey(),
                    'market' => $data->market,
                    'purpose' => $data->purpose,
                    'necessity' => $data->necessity,
                    'balancing' => $data->balancing,
                    'safeguards' => $data->safeguards,
                    'signals_scope' => $data->signalsScope,
                ]);

                if ($data->sign) {
                    if (! $this->gate->isSignable($record)) {
                        throw ComplianceException::assessmentIncomplete();
                    }

                    $record->fill([
                        'status' => LiaStatus::Active,
                        'completed_by' => $member->getKey(),
                        'completed_at' => now(),
                        'review_due_at' => now()->addMonths($this->reviewMonths()),
                        // A re-affirmation after a withdrawal is a new basis, not the old one
                        // resumed — so the withdrawal is cleared and the version moves on.
                        'withdrawn_at' => null,
                        'version' => $record->exists ? $record->version + 1 : 1,
                    ]);
                } elseif (! $record->exists) {
                    $record->status = LiaStatus::Draft;
                }

                $record->save();

                return $record;
            });

            $this->audit->log('compliance.lia_saved', $record, [
                'market' => $record->market->value,
                'status' => $record->status->value,
                'version' => $record->version,
                'signed' => $data->sign,
            ], $member);

            return $record;
        });
    }

    /**
     * Withdraw the basis for a market. Discovery closes immediately; the record stays.
     */
    public function withdraw(Team $team, User $member, LiaRecord $record): LiaRecord
    {
        return $this->teamContext->run($team, function () use ($member, $record): LiaRecord {
            DB::transaction(static function () use ($record): void {
                $record->forceFill([
                    'status' => LiaStatus::Withdrawn,
                    'withdrawn_at' => now(),
                ])->save();
            });

            $this->audit->log('compliance.lia_withdrawn', $record, [
                'market' => $record->market->value,
                'version' => $record->version,
            ], $member);

            return $record;
        });
    }

    private function reviewMonths(): int
    {
        return max(1, (int) config('compliance.lia.review_after_months', 12));
    }
}
