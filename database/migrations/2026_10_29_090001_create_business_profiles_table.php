<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_profiles', function (Blueprint $table): void {
            $table->id();
            // One profile per team (MVP). `unique` enforces it; post-MVP multi-profile would drop this.
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            $table->string('company_name')->nullable();
            $table->string('website')->nullable();
            $table->string('industry')->nullable();
            $table->string('company_size', 32)->nullable();

            // Where the business operates / sells.
            $table->json('locations')->nullable();
            $table->json('regions_served')->nullable();
            $table->string('primary_market', 8)->nullable();

            // What the business offers.
            $table->json('products_services')->nullable();
            $table->text('value_proposition')->nullable();

            // Typical deal size. Money is stored as integer minor units (CLAUDE.md money rule).
            $table->unsignedBigInteger('pricing_min')->nullable();
            $table->unsignedBigInteger('pricing_max')->nullable();
            $table->string('pricing_currency', 3)->nullable();

            $table->json('customer_examples')->nullable();

            // Versionable: bumped each time onboarding is (re)completed.
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique('team_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_profiles');
    }
};
