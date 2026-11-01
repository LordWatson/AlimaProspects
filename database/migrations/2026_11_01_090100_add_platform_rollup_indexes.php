<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Date-only indexes for the ledgers the platform rollup scans (Phase 19c).
 *
 * Every one of these tables is already indexed on `['team_id', <timestamp>]`, which is right for
 * every tenant-facing query in the product and **useless to the rollup**: a composite index can
 * only be used from its leading column, and the rollup is the one reader with no team to narrow by.
 * Without these it is a full table scan per metric per night, growing with the product.
 *
 * `leads` gets one too. It has no timestamp index at all today — the workspace always filters by
 * team first — and the rollup asks "how many prospects were sourced platform-wide yesterday".
 *
 * `audit_logs` is not a rollup source but the same shape of reader: the cross-tenant audit viewer
 * orders by `created_at` across every tenant, which the existing `action` and subject indexes
 * cannot help with.
 */
return new class extends Migration
{
    /**
     * Table => the timestamp column the rollup reads it by.
     *
     * @var array<string, string>
     */
    private const array TARGETS = [
        'ai_requests' => 'created_at',
        'discovery_runs' => 'created_at',
        'signal_runs' => 'created_at',
        'contact_attempts' => 'occurred_at',
        // `sent_at`, not `created_at`: the figure is what we actually sent on the day, and a
        // draft written on Monday and sent on Friday belongs to Friday.
        'outreach_messages' => 'sent_at',
        'leads' => 'created_at',
        'audit_logs' => 'created_at',
    ];

    public function up(): void
    {
        foreach (self::TARGETS as $table => $column) {
            Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                $blueprint->index($column);
            });
        }
    }

    public function down(): void
    {
        foreach (self::TARGETS as $table => $column) {
            Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                $blueprint->dropIndex([$column]);
            });
        }
    }
};
