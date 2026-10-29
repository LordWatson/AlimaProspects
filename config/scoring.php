<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Scoring & Matching configuration
|--------------------------------------------------------------------------
|
| How a lead's ICP-fit score is produced (Phase 9). The score is hybrid: a
| deterministic pass over the ICP's weighted criteria plus an AI qualitative
| assessment (`scoring.qualitative_fit`), blended by the weights below and
| graded A–D by the thresholds. See docs/modules/scoring-matching.md.
|
*/

return [

    // How the two halves of the hybrid score are blended. Any positive pair works — they are
    // normalised by their sum at use, so 60/40 and 6/4 behave identically. When one half is
    // unavailable (no assessable criteria, or the AI call failed) the other carries the score.
    'blend' => [
        'deterministic' => (float) env('SCORING_BLEND_DETERMINISTIC', 0.6),
        'qualitative' => (float) env('SCORING_BLEND_QUALITATIVE', 0.4),
    ],

    // Minimum blended score (0–100) for each grade, highest first. Anything below the C
    // threshold grades D. A hard ICP exclusion always grades D regardless of these.
    'grades' => [
        'a' => (int) env('SCORING_GRADE_A', 80),
        'b' => (int) env('SCORING_GRADE_B', 60),
        'c' => (int) env('SCORING_GRADE_C', 40),
    ],

    // Weight applied to a positive signal the team left unweighted (weight is optional in the ICP
    // editor). Keeps an unweighted criterion influential without letting it dominate.
    'default_signal_weight' => (int) env('SCORING_DEFAULT_WEIGHT', 50),

    // Manual re-scores one member may ask for per minute (the `scoring` rate limiter). Each one
    // spends AI credit; the team's overall allowance is still enforced by the AI Platform.
    'rescore_rate_limit' => (int) env('SCORING_RESCORE_RATE_LIMIT', 10),

    // Leads per chunk when re-scoring a whole team after its ICP changed. Each chunk dispatches
    // one job per lead, so this only bounds memory, not concurrency.
    'rescore_chunk' => (int) env('SCORING_RESCORE_CHUNK', 200),

    // Country names that mean the same place, so a geography criterion written as "UAE" still
    // matches a lead whose provider stored "AE". Kept to the MVP markets (ADR 0007) plus the
    // neighbours a UK/UAE team is most likely to type; extend as markets are added.
    'country_aliases' => [
        ['gb', 'uk', 'united kingdom', 'great britain', 'britain'],
        ['ae', 'uae', 'united arab emirates', 'emirates'],
        ['ie', 'ireland', 'republic of ireland'],
        ['sa', 'ksa', 'saudi arabia'],
        ['us', 'usa', 'united states', 'united states of america'],
    ],

];
