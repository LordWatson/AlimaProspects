<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signal_clusters', function (Blueprint $table): void {
            // One real-world event, however many outlets reported it (S11). The cards a team sees
            // are clusters; the signals under one are the coverage.
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            // `sha1(event key | subject fingerprint | published week)` — deterministic, computed by
            // `SignalClusterer`, and the whole reason two runs a day apart join the same cluster
            // rather than making two. Unique per team, which is what makes "find or create" the
            // only write path.
            $table->char('cluster_key', 40);

            // The type this event is, both ways round, for exactly the reason `signals` carries
            // both (ADR 0018 §4): the id is the live attribution and the key is what survives the
            // definition being deleted.
            $table->foreignId('signal_definition_id')
                ->nullable()
                ->constrained('signal_definitions')
                ->nullOnDelete();
            $table->string('event_key', 32);

            // The signal that represents this cluster: the highest-confidence one, earliest
            // publication breaking ties, because the first report of an event is usually the best
            // one. Nullable and `nullOnDelete` so retention can take a member without taking the
            // cluster; `SignalClusterer::reconcile()` then picks the next one.
            $table->foreignId('primary_signal_id')
                ->nullable()
                ->constrained('signals')
                ->nullOnDelete();

            // How many outlets reported it, and the window it spans. A **record** over the member
            // signals rather than a counter the app increments — the same shape as `usage_meters`
            // (ADR 0012) and for the same reason: a counter and its rows disagree eventually.
            $table->unsignedInteger('sources_count')->default(0);
            $table->timestamp('first_published_at')->nullable();
            $table->timestamp('last_published_at')->nullable();

            // The company the event is about, as `CompanyNameKey::bare()` renders it — the same
            // rule `SignalLeadMatcher` and `SourcedLead::dedupeKey()` use, because a third name
            // normaliser is how two leads become three.
            //
            // **A company name and never a person's.** A cluster is not swept by retention (its
            // signals are), so a person's name here would be a new personal-data surface outliving
            // the rows it came from. A signal naming only a person is therefore left unclustered —
            // see the module doc's "Known limitations".
            $table->string('subject_fingerprint', 160)->nullable();

            $table->timestamps();

            // One cluster per key per team.
            $table->unique(['team_id', 'cluster_key'], 'signal_clusters_team_key_unique');
            // The sweep that removes clusters whose signals have all gone, and any "recent events"
            // read over the table.
            $table->index(['team_id', 'last_published_at'], 'signal_clusters_team_published_index');
        });

        Schema::table('signals', function (Blueprint $table): void {
            // The event this article is coverage of, or null when it stands alone. Added in a
            // second statement because the FK points back at `signal_clusters`, which points at
            // this table: the pair has to be created in two steps whichever way round it is read.
            $table->foreignId('signal_cluster_id')
                ->nullable()
                ->after('signal_definition_id')
                ->constrained('signal_clusters')
                ->nullOnDelete();
        });

        Schema::table('signals', function (Blueprint $table): void {
            // The feed reads "the cluster's primary, plus every unclustered signal", so this is
            // the index behind the only list in the product that is now a two-part question.
            $table->index(['team_id', 'signal_cluster_id'], 'signals_team_cluster_index');
        });
    }

    public function down(): void
    {
        Schema::table('signals', function (Blueprint $table): void {
            $table->dropIndex('signals_team_cluster_index');
            $table->dropConstrainedForeignId('signal_cluster_id');
        });

        Schema::dropIfExists('signal_clusters');
    }
};
