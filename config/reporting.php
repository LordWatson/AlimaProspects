<?php

declare(strict_types=1);

return [

    // Buckets the trend chart falls back to when the dashboard's window is open-ended (all time).
    // A quarter of weeks: long enough to see a direction, short enough to read on a phone. Any
    // bounded window picks its own bucket width from its length (see TrendGranularity).
    'trend_periods' => (int) env('REPORTING_TREND_PERIODS', 12),

    // CSV exports one member may ask for per minute — the `reports-export` rate limiter. An export
    // runs every aggregation on the dashboard, so it is cheap to click and not cheap to serve.
    'export_rate_limit' => (int) env('REPORTING_EXPORT_RATE_LIMIT', 20),

];
