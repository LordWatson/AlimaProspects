<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widen `leads.source_url` and `lead_contacts.source_url` from the default 255 to **2048**, the
 * width `signals.source_url` has had since S3.
 *
 * Both columns hold the address of the page a fact came from, and an article URL is routinely
 * longer than 255 characters — Serper's `/news` sometimes answers with a Google redirect
 * (`https://google.com/goto?url=<opaque token>`), which runs to ~280. At 255 that meant:
 *
 * - **converting such a signal threw** `SQLSTATE[22001]` on the contact insert, so the signals a
 *   member most wanted were the ones that couldn't be converted; and
 * - the lead's own `source_url` was **silently truncated** by `SourcedLead::safeUrl()` to fit,
 *   leaving the origin panel showing a link that 404s — the worse of the two failures, because
 *   nothing reported it.
 *
 * 2048 is the de-facto URL ceiling and matches `signals`, so provenance survives the trip from a
 * signal to a lead to a contact intact. Neither column is indexed, so the width costs nothing.
 */
return new class extends Migration
{
    private const int WIDE = 2048;

    private const int NARROW = 255;

    /** @var list<string> */
    private const array TABLES = ['leads', 'lead_contacts'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->string('source_url', self::WIDE)->nullable()->change();
            });
        }
    }

    /**
     * Narrowing cannot be lossless, so it **refuses** rather than quietly cutting URLs down to a
     * length that no longer resolves. The same discipline as the S6 converted-signal migration: a
     * rollback that corrupts provenance is worse than one that stops and says why.
     */
    public function down(): void
    {
        foreach (self::TABLES as $table) {
            $tooLong = DB::table($table)
                ->whereNotNull('source_url')
                ->whereRaw('LENGTH(source_url) > ?', [self::NARROW])
                ->count();

            if ($tooLong > 0) {
                throw new RuntimeException(
                    "Cannot narrow {$table}.source_url to ".self::NARROW.": {$tooLong} row(s) hold a "
                    .'longer URL and would be truncated into a broken link. Shorten or clear them first.'
                );
            }
        }

        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->string('source_url', self::NARROW)->nullable()->change();
            });
        }
    }
};
