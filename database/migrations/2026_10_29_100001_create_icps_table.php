<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('icps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();
            // Exactly one active ICP per team (enforced by SetActiveIcpAction, not a DB constraint
            // — MySQL has no partial unique index). Indexed so "the active ICP" resolves cheaply.
            $table->boolean('is_active')->default(false);

            $table->timestamps();

            $table->index(['team_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('icps');
    }
};
