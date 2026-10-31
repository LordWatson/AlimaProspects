<?php

declare(strict_types=1);

use App\Modules\Admin\Services\PlatformSettings;
use App\Modules\Billing\Contracts\StripeSwitch;
use App\Modules\Billing\Enums\PlanKey;
use App\Modules\Billing\Listeners\SyncSubscriptionFromStripe;
use App\Modules\Compliance\Models\LiaRecord;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Models\Notification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Laravel\Cashier\Events\WebhookHandled;
use Tests\Support\BillingFixtures as Billing;
use Tests\Support\ComplianceFixtures as Compliance;

uses(RefreshDatabase::class);

/*
 * The two producers Phase 19b added (`notifications.md`'s "producer gap").
 *
 * Both close the same shape of hole: a rule the product enforced correctly and never told anybody
 * about. A failed payment ended in a cancelled subscription the team learned about from a locked
 * screen; a lapsed lawful-basis assessment ended in a refused discovery run the team read as a bug.
 * The gate was working perfectly in both cases and the experience was indefensible — which is why
 * these are notifications and not more logging.
 */

function notificationsOfType(NotificationType $type): Collection
{
    // `data.type`, because the `type` column holds the notification class — these go out through
    // Laravel's own database channel.
    return Notification::query()->withoutGlobalScopes()->get()->where('data.type', $type->value);
}

function paymentFailedWebhook(string $customer, int $attempts = 1): WebhookHandled
{
    return new WebhookHandled([
        'type' => 'invoice.payment_failed',
        'data' => ['object' => ['customer' => $customer, 'attempt_count' => $attempts]],
    ]);
}

// --- A failed payment ------------------------------------------------------------------------------

it('tells the people who can fix a failed payment', function (): void {
    [$owner, $team] = Billing::team();
    Billing::subscribe($team, PlanKey::Scale);
    $team->forceFill(['stripe_id' => 'cus_producergap'])->save();

    $member = Billing::addMember($team, TeamRole::Member);

    app(SyncSubscriptionFromStripe::class)->handle(paymentFailedWebhook('cus_producergap', 2));

    $sent = notificationsOfType(NotificationType::PaymentFailed);
    $recipients = $sent->pluck('notifiable_id')->map(intval(...))->all();

    // `toAdmins`, not `toTeam`: it is money. A Member can neither update the card nor be expected
    // to care, and telling them reads as "your company is about to be cut off" from somebody with
    // no way to act on it.
    expect($recipients)->toContain((int) $owner->getKey())
        ->and($recipients)->not->toContain((int) $member->getKey())
        // The attempt count travels, because "we have tried twice" and "this was the last attempt"
        // are different messages and Stripe is the only thing that knows which. Stored as a
        // **param**, never as a finished sentence: the row is read days later, possibly after the
        // member switched the app to Arabic.
        ->and($sent->first()->params()['count'] ?? null)->toBe(2)
        ->and($sent->first()->data['body'] ?? '')->toContain(':count');
});

it('stamps a failed-payment notice with the team it belongs to', function (): void {
    [, $alpha] = Billing::team();
    Billing::subscribe($alpha, PlanKey::Scale);
    $alpha->forceFill(['stripe_id' => 'cus_alpha'])->save();

    [, $beta] = Billing::team();
    Billing::subscribe($beta, PlanKey::Scale);
    $beta->forceFill(['stripe_id' => 'cus_beta'])->save();

    app(SyncSubscriptionFromStripe::class)->handle(paymentFailedWebhook('cus_beta'));

    $teamIds = notificationsOfType(NotificationType::PaymentFailed)
        ->pluck('team_id')->map(intval(...))->unique()->values()->all();

    // A webhook arrives with no request and no team in context, so the `team_id` on the row comes
    // from the customer the event names. Getting that wrong would put one tenant's billing trouble
    // in another tenant's bell.
    expect($teamIds)->toBe([(int) $beta->getKey()])
        ->and($teamIds)->not->toContain((int) $alpha->getKey());
});

it('says nothing about a payment when Stripe is switched off', function (): void {
    [, $team] = Billing::team();
    Billing::subscribe($team, PlanKey::Scale);
    $team->forceFill(['stripe_id' => 'cus_switchedoff'])->save();

    app(PlatformSettings::class)->set('billing.stripe_enabled', false);

    // `PlatformSettings` is `scoped` and the listener reads the switch per call, so a fresh
    // container instance is what the next request — or the next webhook — would see.
    app()->forgetInstance(PlatformSettings::class);
    app()->forgetInstance(StripeSwitch::class);

    app(SyncSubscriptionFromStripe::class)->handle(paymentFailedWebhook('cus_switchedoff'));

    // An operator who has switched Stripe off is dealing with something broken. A webhook that
    // slipped through must not tell a customer their payment failed on our behalf.
    expect(notificationsOfType(NotificationType::PaymentFailed))->toHaveCount(0);
});

// --- A lapsing assessment --------------------------------------------------------------------------

it('warns a team before its lawful-basis assessment lapses', function (): void {
    [$owner, $team] = Compliance::team();
    $record = Compliance::activeLia($team);

    // Exactly fourteen days out — the day it crosses the threshold.
    $record->forceFill(['review_due_at' => CarbonImmutable::now('UTC')->addDays(14)])->save();

    $this->artisan('compliance:notify-reviews')->assertSuccessful();

    $sent = notificationsOfType(NotificationType::AssessmentReviewDue);

    // The point of the command: a team that had stopped visiting the compliance centre used to
    // find out when their next discovery run was refused.
    expect($sent)->toHaveCount(1)
        ->and((int) $sent->first()->notifiable_id)->toBe((int) $owner->getKey())
        ->and($sent->first()->params()['count'] ?? null)->toBe(14)
        ->and($record->exists)->toBeTrue();
});

it('warns again on the day the gate actually closes', function (): void {
    [, $team] = Compliance::team();
    $record = Compliance::activeLia($team);
    $record->forceFill(['review_due_at' => CarbonImmutable::now('UTC')])->save();

    $this->artisan('compliance:notify-reviews')->assertSuccessful();

    // Two notices, not one. The second exists because somebody who ignored the first needs to know
    // *why* sourcing stopped, rather than filing a bug against discovery.
    $sent = notificationsOfType(NotificationType::AssessmentReviewDue);

    expect($sent)->toHaveCount(1)
        ->and($sent->first()->params()['count'] ?? null)->toBe(0)
        // And it says the thing that makes somebody open it: not "due for review" but "discovery
        // is paused".
        ->and($sent->first()->data['body'] ?? '')->toContain('paused');
});

it('does not nag every day in between', function (): void {
    [, $team] = Compliance::team();
    $record = Compliance::activeLia($team);
    $record->forceFill(['review_due_at' => CarbonImmutable::now('UTC')->addDays(9)])->save();

    // Nine days out is **inside** the fortnight's window, so the warning is owed — the earlier
    // version fired only on the exact day the threshold was crossed, which meant a morning the
    // scheduler missed lost the notice entirely and the team found out when discovery stopped.
    $this->artisan('compliance:notify-reviews')->assertSuccessful();
    expect(notificationsOfType(NotificationType::AssessmentReviewDue))->toHaveCount(1);

    // And then nothing, however many times it runs. `review_notified_at` is what keeps a window
    // wide enough to survive a missed day from becoming a daily reminder for a fortnight — which
    // is how a team learns to ignore the one that matters.
    $this->travel(3)->days();
    $this->artisan('compliance:notify-reviews')->assertSuccessful();
    $this->travel(3)->days();
    $this->artisan('compliance:notify-reviews')->assertSuccessful();

    expect(notificationsOfType(NotificationType::AssessmentReviewDue))->toHaveCount(1)
        ->and($record->refresh()->review_notified_at)->not->toBeNull();
});

it('still says something on the day the gate closes, having warned already', function (): void {
    [, $team] = Compliance::team();
    $record = Compliance::activeLia($team);
    $record->forceFill(['review_due_at' => CarbonImmutable::now('UTC')->addDays(2)])->save();

    $this->artisan('compliance:notify-reviews')->assertSuccessful();
    expect(notificationsOfType(NotificationType::AssessmentReviewDue))->toHaveCount(1);

    $this->travel(3)->days();
    $this->artisan('compliance:notify-reviews')->assertSuccessful();

    // Two notices per cycle, and the second is the one that matters: somebody who ignored the
    // warning needs to know *why* sourcing stopped rather than filing a bug against discovery.
    // One column tells them apart — a stamp before the due date is the warning, after it the lapse.
    $sent = notificationsOfType(NotificationType::AssessmentReviewDue);

    expect($sent)->toHaveCount(2)
        ->and($sent->last()->params()['count'] ?? null)->toBe(0);

    // And not a third.
    $this->travel(1)->day();
    $this->artisan('compliance:notify-reviews')->assertSuccessful();

    expect(notificationsOfType(NotificationType::AssessmentReviewDue))->toHaveCount(2);
});

it('tells only the people who can sign the assessment', function (): void {
    [$owner, $team] = Compliance::team();
    $record = Compliance::activeLia($team);
    $record->forceFill(['review_due_at' => CarbonImmutable::now('UTC')->addDays(14)])->save();

    $member = Compliance::member($team, TeamRole::Member);

    $this->artisan('compliance:notify-reviews')->assertSuccessful();

    $recipients = notificationsOfType(NotificationType::AssessmentReviewDue)
        ->pluck('notifiable_id')->map(intval(...))->all();

    // It is a legal record signed by a named person, and a Member cannot complete it.
    expect($recipients)->toContain((int) $owner->getKey())
        ->and($recipients)->not->toContain((int) $member->getKey());
});

it('leaves a withdrawn assessment alone', function (): void {
    [, $team] = Compliance::team();
    $record = Compliance::activeLia($team);

    $record->forceFill([
        'review_due_at' => CarbonImmutable::now('UTC')->addDays(14),
        'withdrawn_at' => CarbonImmutable::now('UTC')->subDay(),
    ])->save();

    $this->artisan('compliance:notify-reviews')->assertSuccessful();

    // A withdrawn record is not one somebody forgot to review — it is one they decided against,
    // and chasing them about it would be us arguing with a decision they already made.
    expect(notificationsOfType(NotificationType::AssessmentReviewDue))->toHaveCount(0)
        ->and(LiaRecord::query()->withoutGlobalScopes()->count())->toBe(1);
});
