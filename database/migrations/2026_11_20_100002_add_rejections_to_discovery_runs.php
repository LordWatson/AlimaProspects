<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a run left out, and why (docs/plans/ai-relevance-plan.md P2, ADR 0035).
 *
 * The relevance gate refuses candidates before they become leads, so there is no lead row to hang
 * the refusal on. The count is the honest half of `leads_found`; the sample (a company name, a
 * verdict, a one-line reason — company-level only, capped) is what lets a member see that the gate
 * dropped the right things and tune their profile when it did not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discovery_runs', function (Blueprint $table): void {
            $table->unsignedInteger('leads_rejected')->default(0)->after('leads_created');
            $table->json('rejections')->nullable()->after('leads_rejected');
        });
    }

    public function down(): void
    {
        Schema::table('discovery_runs', function (Blueprint $table): void {
            $table->dropColumn(['leads_rejected', 'rejections']);
        });
    }
};
