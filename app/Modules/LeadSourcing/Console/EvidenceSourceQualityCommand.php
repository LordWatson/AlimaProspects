<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Console;

use App\Modules\LeadSourcing\Enums\EvidenceVerdict;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Reports how often each evidence source is flagged **wrong**, across every team (PL1).
 *
 * It **proposes nothing and changes nothing** — least of all `evidence.reliability`. A source's
 * weight is a position the business has taken about an institution, and a handful of flags is a
 * reason to go and look rather than a mandate to re-weight; ADR 0022 settled that shape for the
 * signal tuning advisor and the same argument applies here. What this gives an operator is the one
 * number that is hard to get any other way: *which of our sources are our customers telling us are
 * wrong about companies*.
 *
 * Cross-tenant on purpose, which is why it is a console command and not a screen: the question is
 * about a **source**, and one team's flags are too few to answer it. Nothing it emits names a team,
 * a lead or a person — a source key, counts and a rate.
 */
final class EvidenceSourceQualityCommand extends Command
{
    protected $signature = 'evidence:source-quality {--days= : Window, defaulting to config}';

    protected $description = 'Report the wrong-flag rate per evidence source (PL1).';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('evidence.source_quality.window_days', 30));
        $since = Carbon::now()->subDays(max(1, $days));

        $rows = $this->rates($since);

        if ($rows === []) {
            $this->components->info('No evidence feedback in the window.');

            return self::SUCCESS;
        }

        $minVerdicts = (int) config('evidence.source_quality.min_verdicts', 10);
        $alertRate = (float) config('evidence.source_quality.wrong_rate_alert', 0.25);

        $this->table(
            ['Source', 'Verdicts', 'Wrong', 'Rate', ''],
            array_map(static function (array $row) use ($minVerdicts, $alertRate): array {
                $significant = $row['verdicts'] >= $minVerdicts;

                return [
                    $row['source'],
                    $row['verdicts'],
                    $row['wrong'],
                    number_format($row['rate'] * 100, 1).'%',
                    // A rate over a handful of verdicts is noise, and flagging it would train an
                    // operator to ignore this report.
                    match (true) {
                        ! $significant => 'too few to judge',
                        $row['rate'] >= $alertRate => 'REVIEW',
                        default => '',
                    },
                ];
            }, $rows),
        );

        foreach ($rows as $row) {
            if ($row['verdicts'] >= $minVerdicts && $row['rate'] >= $alertRate) {
                // Structured, no prose, no ids: an operator's cue to open the source's own terms
                // and spike it again, which is what ADR 0025 established for a source we doubt.
                Log::channel('lead_sourcing')->warning('Evidence source is often flagged wrong.', [
                    'source' => $row['source'],
                    'verdicts' => $row['verdicts'],
                    'wrong' => $row['wrong'],
                    'rate' => round($row['rate'], 3),
                    'window_days' => $days,
                ]);
            }
        }

        return self::SUCCESS;
    }

    /**
     * Wrong-verdict rate per source over the window.
     *
     * One grouped query over the join rather than a read-then-count in PHP: this is cross-tenant,
     * so the row count is every team's feedback together, and the aggregate belongs in the
     * database. `withoutGlobalScopes` because there is no team in context and the question is
     * deliberately about all of them.
     *
     * @return list<array{source: string, verdicts: int, wrong: int, rate: float}>
     */
    private function rates(Carbon $since): array
    {
        $wrong = EvidenceVerdict::Wrong->value;

        $rows = DB::table('lead_evidence_feedback')
            ->join('lead_evidence', 'lead_evidence.id', '=', 'lead_evidence_feedback.lead_evidence_id')
            ->where('lead_evidence_feedback.created_at', '>=', $since)
            ->select('lead_evidence.source')
            ->selectRaw('count(*) as verdicts')
            // `case when` rather than a driver-specific conditional aggregate: SQLite (tests) and
            // MySQL (production) both read this, and `sum(verdict = ?)` is MySQL's alone. Bound
            // rather than interpolated — the value is our own enum's, but a raw clause that
            // concatenates anything is a raw clause somebody later concatenates a column into.
            ->selectRaw('sum(case when lead_evidence_feedback.verdict = ? then 1 else 0 end) as wrong', [$wrong])
            ->groupBy('lead_evidence.source')
            ->orderBy('lead_evidence.source')
            ->get();

        $rates = $rows
            ->map(static function (object $row): array {
                $verdicts = (int) $row->verdicts;
                $wrongCount = (int) $row->wrong;

                return [
                    'source' => (string) $row->source,
                    'verdicts' => $verdicts,
                    'wrong' => $wrongCount,
                    'rate' => $verdicts === 0 ? 0.0 : (float) $wrongCount / $verdicts,
                ];
            })
            ->sortByDesc('rate')
            ->all();

        return array_values($rates);
    }
}
