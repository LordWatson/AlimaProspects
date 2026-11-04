<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signal_definition_submissions', function (Blueprint $table): void {
            // A team offering one of their own signal types to the platform library (S15 task 4,
            // plan decision #3: **an admin curates; a team can only submit**).
            //
            // The decision this table encodes is who gets to put something in front of every other
            // customer. A team publishing straight to the library would mean one tenant's taste —
            // or one tenant's mistake, or one tenant's attempt at advertising — arriving in
            // everybody else's library on a deploy nobody reviewed. So a submission is a request,
            // and promotion is an operator's act with a note attached.
            $table->id();

            // The author. **Named here and anonymised on the way out**: the review screen shows a
            // type's cross-tenant performance and not whose it is, because an operator choosing
            // between two submissions should be choosing on the evidence. The column exists because
            // the team has to be told the answer, and because a withdrawn submission has to be
            // findable by the team that made it.
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();

            // The type being offered. `cascadeOnDelete`, unlike almost every other foreign key in
            // this module: a submission is a *request about a row*, and with the row gone there is
            // nothing to promote. Keeping it would leave an operator reviewing something that
            // cannot be acted on — the opposite of the run log's reasoning, where the record of
            // what happened is the valuable part.
            $table->foreignId('signal_definition_id')->constrained('signal_definitions')->cascadeOnDelete();

            // A `SignalSubmissionStatus`. An enum because the panel branches hard on it.
            $table->string('status', 16);

            // What the team said when they offered it, and what the operator said when they
            // answered. Both are prose a person typed for another person to read, which is the one
            // kind of text this codebase stores as prose rather than as a key — there is nothing
            // to translate about somebody's own words.
            $table->text('note')->nullable();
            $table->text('review_note')->nullable();

            // The preset promotion produced, so the team can be shown what became of their
            // submission and the operator can find it again. `nullOnDelete`, because withdrawing a
            // preset from the library must not erase the record of where it came from.
            $table->foreignId('preset_id')
                ->nullable()
                ->constrained('signal_definitions')
                ->nullOnDelete();

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            // **One open submission per type.** A team pressing the button twice is asking the same
            // question, and an operator seeing it twice has to work out whether the second is a
            // revision. Enforced partially rather than absolutely — a rejected submission may be
            // offered again once the team has changed something — so the unique is on the pair and
            // the Action refuses a second *pending* one.
            $table->unique(['signal_definition_id', 'status'], 'signal_submissions_definition_status_unique');

            // The review queue's read: everything waiting, oldest first, because a submission
            // nobody has answered is somebody waiting.
            $table->index(['status', 'created_at'], 'signal_submissions_status_queue_index');
            $table->index(['team_id', 'created_at'], 'signal_submissions_team_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signal_definition_submissions');
    }
};
