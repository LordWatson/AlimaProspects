<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What Alima knows about the seller that the onboarding wizard never asked (docs/plans/
 * ai-relevance-plan.md). The background check learned these and, until now, had nowhere to put
 * them: accepting a report wrote two fields and the rest stayed inside the report, where neither
 * the ICP composer, the query shaper nor the scorer could read it.
 *
 * They go on the **profile** rather than behind a seam into Business Intelligence, because the
 * profile is already the one thing every one of those consumers reads. A finding the team accepted
 * is, from then on, the team's own description of itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_profiles', function (Blueprint $table): void {
            // Who buys, and who sells the same thing.
            $table->json('customer_industries')->nullable()->after('customer_examples');
            $table->json('target_customers')->nullable()->after('customer_industries');
            $table->json('buyer_titles')->nullable()->after('target_customers');
            $table->json('competitors')->nullable()->after('buyer_titles');
            // Why and when they buy, and who never should.
            $table->json('problems_solved')->nullable()->after('competitors');
            $table->json('buying_triggers')->nullable()->after('problems_solved');
            $table->json('anti_customers')->nullable()->after('buying_triggers');
            // How far the business can serve (`DeliveryModel`), and how big its customers are.
            $table->string('delivery_model', 32)->nullable()->after('anti_customers');
            $table->string('customer_size_range', 64)->nullable()->after('delivery_model');
        });
    }

    public function down(): void
    {
        Schema::table('business_profiles', function (Blueprint $table): void {
            $table->dropColumn([
                'customer_industries', 'target_customers', 'buyer_titles', 'competitors',
                'problems_solved', 'buying_triggers', 'anti_customers', 'delivery_model', 'customer_size_range',
            ]);
        });
    }
};
