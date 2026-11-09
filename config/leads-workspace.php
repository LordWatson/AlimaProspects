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

    // The countries the add-a-lead form offers, as ISO-3166-1 alpha-2 => display name. A short list
    // on purpose: the MVP markets (ADR 0007) plus the neighbours a UK/UAE team is most likely to be
    // working, which is the same scope `scoring.country_aliases` keeps. A lead from anywhere else is
    // added with no country and enrichment fills it in — better than a 250-entry picker on a phone,
    // or a two-letter code a member has to know. Extend it as markets are added.
    'countries' => [
        'GB' => 'United Kingdom',
        'AE' => 'United Arab Emirates',
        'IE' => 'Ireland',
        'SA' => 'Saudi Arabia',
        'US' => 'United States',
    ],

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

    // PL6's export — the one a member chooses columns for, which is metered and keeps its file.
    'export' => [
        // Up to this many rows the file is written in the request, because a member who clicked
        // Export wants a file and not a promise. Past it a worker does it and a notification
        // carries the link. Raising it a long way means a request that outlives a PHP timeout.
        'sync_max_rows' => (int) env('LEADS_EXPORT_SYNC_MAX_ROWS', 500),

        // How long a finished file is kept before `leads:prune-exports` deletes it. A team's whole
        // lead list sitting on a disk for ever is a breach waiting for a misconfigured bucket; a
        // week is long enough for somebody to come back from leave and download it.
        'retain_days' => (int) env('LEADS_EXPORT_RETAIN_DAYS', 7),

        // The filesystem disk. `local` is `storage/app/private` — **never** the public disk: a
        // file of leads behind a guessable URL is the whole list, unauthenticated.
        'disk' => (string) env('LEADS_EXPORT_DISK', 'local'),
    ],

];
