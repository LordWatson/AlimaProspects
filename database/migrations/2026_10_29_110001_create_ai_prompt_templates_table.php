<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_prompt_templates', function (Blueprint $table): void {
            // The versioned prompt registry (AI Platform). A prompt is referenced by key + version;
            // changing a prompt means adding a new version, never editing one in place. Global
            // (not team-scoped) — templates are product configuration, not tenant data.
            $table->id();

            $table->string('key');                 // <module>.<purpose>, e.g. business_intel.background_check
            $table->unsignedInteger('version');     // monotonically increasing per key
            $table->string('tier');                 // AiTier value: reasoning | cheap

            $table->text('system')->nullable();     // system/instructions prompt
            $table->text('body');                   // user prompt template with {{placeholders}}
            $table->json('schema')->nullable();     // JSON schema the response is validated against

            $table->unsignedInteger('max_tokens')->nullable();
            // Temperature 0.00–2.00, stored to two decimals; null falls back to the config default.
            $table->decimal('temperature', 3, 2)->nullable();

            $table->string('description')->nullable();
            // Exactly one active version per key is resolved when no explicit version is requested.
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['key', 'version']);
            $table->index(['key', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_prompt_templates');
    }
};
