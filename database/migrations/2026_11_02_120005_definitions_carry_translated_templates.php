<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A signal type may carry its search templates in more than one language (S8, ADR 0019 §5).
     *
     * `hl` used to be one product-wide setting documented as "v1: English only"; it is a property
     * of the region now, so `ae` can be searched in Arabic. That only helps if the *templates* are
     * Arabic too — a `gl=ae&hl=ar` search with English keywords is still an English search.
     *
     * Shaped as `{ "<language>": ["…", "…"] }` rather than a second `query_templates_ar` column,
     * because the next language should be a key and not a migration. A type with no entry for a
     * language falls back to `query_templates`, which is the behaviour that matters: adding an
     * Arabic region must never silently empty a watch, and nothing is translated yet.
     *
     * **This phase ships the plumbing only.** Writing Arabic query templates is a content task for
     * the native-speaker pass already pending in `docs/modules/signals.md`, and a machine-translated
     * search query is worse than no translation — it finds nothing and looks like it works.
     */
    public function up(): void
    {
        Schema::table('signal_definitions', function (Blueprint $table): void {
            $table->json('query_templates_i18n')->nullable()->after('query_templates');
        });
    }

    public function down(): void
    {
        Schema::table('signal_definitions', function (Blueprint $table): void {
            $table->dropColumn('query_templates_i18n');
        });
    }
};
