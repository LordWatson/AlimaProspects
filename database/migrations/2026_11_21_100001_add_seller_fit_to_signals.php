<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Signals v3 P1: the classifier's judgement of whether the company an event is about is one the
 * team would sell to, and the one sentence saying why the event matters to them.
 *
 * All nullable: a row written before this, a registry filing that skipped the classifier, and an
 * article that did not say enough to judge all have no verdict, and null is not "unknown fit".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signals', function (Blueprint $table): void {
            $table->string('seller_fit', 16)->nullable()->after('relevance');
            $table->unsignedTinyInteger('seller_fit_confidence')->nullable()->after('seller_fit');
            $table->string('why_it_matters', 255)->nullable()->after('seller_fit_confidence');
        });

        Schema::table('signal_runs', function (Blueprint $table): void {
            // Real events this run left out because their subject is not a customer.
            $table->unsignedInteger('not_fit_count')->default(0)->after('signals_created');
        });
    }

    public function down(): void
    {
        Schema::table('signals', function (Blueprint $table): void {
            $table->dropColumn(['seller_fit', 'seller_fit_confidence', 'why_it_matters']);
        });

        Schema::table('signal_runs', function (Blueprint $table): void {
            $table->dropColumn('not_fit_count');
        });
    }
};
