<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Leads Workspace configuration
|--------------------------------------------------------------------------
|
| The leads list, the kanban board and CSV import/export (Phases 10/10b).
| See docs/modules/leads-workspace.md.
|
*/

return [

    // The board a team starts with, created the first time it opens the pipeline. A team can
    // rename, reorder, add to and remove these from the board itself, so this is only ever the
    // starting point — nothing in the platform keys off these names. Exactly one entry must be
    // `default` (where a lead sits before anyone decides anything about it), and `outcome` is
    // `won`, `lost` or null (see StageOutcome).
    'default_stages' => [
        ['name' => 'New', 'slug' => 'new', 'default' => true, 'outcome' => null],
        ['name' => 'Working', 'slug' => 'working', 'default' => false, 'outcome' => null],
        ['name' => 'Qualified', 'slug' => 'qualified', 'default' => false, 'outcome' => null],
        ['name' => 'Won', 'slug' => 'won', 'default' => false, 'outcome' => 'won'],
        ['name' => 'Lost', 'slug' => 'lost', 'default' => false, 'outcome' => 'lost'],
    ],

    // Most stages one team's board may hold. A board is for scanning; past a couple of dozen
    // columns it stops being one.
    'max_stages' => (int) env('LEADS_MAX_STAGES', 20),

    // Leads loaded per board column. The board is a summary, not the list — a column with more
    // than this says so and links to the filtered list.
    'board_column_limit' => (int) env('LEADS_BOARD_COLUMN_LIMIT', 25),

    // Most leads one bulk action may touch. Bounds the work a single click can start.
    'max_bulk_leads' => (int) env('LEADS_MAX_BULK', 500),

    // Bulk requests (a bulk action, a CSV import, a CSV export) one member may make per minute —
    // the `leads-bulk` rate limiter. Each is cheap to ask for and expensive to serve.
    'bulk_rate_limit' => (int) env('LEADS_BULK_RATE_LIMIT', 20),

    'csv' => [
        // Largest import file accepted, in kilobytes (validated on the upload).
        'max_upload_kb' => (int) env('LEADS_CSV_MAX_UPLOAD_KB', 5_120),

        // Most data rows one import will read. Anything beyond this is left for a second file, so
        // one upload can't tie up a request indefinitely.
        'max_import_rows' => (int) env('LEADS_CSV_MAX_IMPORT_ROWS', 2_000),

        // Rows written per chunk while streaming an export, so a large export never holds the
        // whole result set in memory.
        'export_chunk' => (int) env('LEADS_CSV_EXPORT_CHUNK', 200),
    ],

];
