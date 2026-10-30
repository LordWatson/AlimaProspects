<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            // --- Compliance & Privacy (Phase 15) ---
            // When the retention sweep stripped the personal data from this lead
            // (docs/compliance.md §3.4). The company record and the fact of each approach survive;
            // the contacts, the message text and the attempt notes do not.
            //
            // The column is what makes the sweep **idempotent**: an already-anonymised lead is
            // skipped rather than re-processed every night for the rest of its life. It is also
            // what the lead screen reads to say "personal data removed on 4 March" instead of
            // showing a company with mysteriously no contacts.
            $table->timestamp('anonymised_at')->nullable()->after('has_personal_data');

            // The sweep's candidate query: this team's leads that have not been anonymised yet.
            $table->index(['team_id', 'anonymised_at']);
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->dropIndex(['team_id', 'anonymised_at']);
            $table->dropColumn('anonymised_at');
        });
    }
};
