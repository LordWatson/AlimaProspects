<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a team was last told their lawful-basis assessment needs reviewing (Phase 19b).
 *
 * `NotifyAssessmentReviewsCommand` shipped without this and fired only on the **exact** day a
 * review crossed the warning threshold, which made the notice depend on the scheduler having run
 * that morning: a missed day meant the team was never warned at all, and then lead discovery
 * stopped for a reason nobody had told them. Widening the window without this column would have
 * sent the warning every day for a fortnight instead, which is how a team learns to ignore the one
 * that matters.
 *
 * One column rather than two, because the two notices can be told apart by comparing it against
 * `review_due_at`: a stamp **before** the due date is the warning, a stamp after it is the lapse
 * notice. Re-signing the assessment moves `review_due_at` forward, which starts the cycle again
 * with no reset to remember.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lia_records', function (Blueprint $table): void {
            $table->timestamp('review_notified_at')->nullable()->after('review_due_at');
        });
    }

    public function down(): void
    {
        Schema::table('lia_records', function (Blueprint $table): void {
            $table->dropColumn('review_notified_at');
        });
    }
};
