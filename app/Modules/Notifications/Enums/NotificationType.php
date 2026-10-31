<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Enums;

/**
 * What a notification is about (Phase 12). The single registry of everything the platform tells a
 * member, and the only place a new one has to be declared.
 *
 * Every property here answers a question a new type has to answer before it can exist:
 *
 * - {@see self::defaultChannels()} — how loudly, before the member has said. The defaults are
 *   **conservative about email**: in-app costs a member nothing, an email they didn't ask for costs
 *   them their attention and costs us their trust in the next one. So email is on by default only
 *   where the thing is time-sensitive and the member would be cross to have missed it.
 * - {@see self::isDigestible()} — whether it can wait for the daily round-up instead. The point of
 *   a digest is to turn ten small notifications into one; a type that must arrive now is excluded
 *   from it rather than delayed by it.
 * - {@see self::group()} — how the preference screen arranges them, so a member reads a short list
 *   of headings rather than a list of twelve switches.
 *
 * Values are stable storage keys; the labels are translation keys the screens pass through `t()`
 * (EN/AR, ADR 0007). Nothing in `notifications.data` is ever a formed sentence.
 */
enum NotificationType: string
{
    // --- Lead Sourcing (Phase 7) ---
    /** A discovery run finished and found (or didn't find) leads. */
    case DiscoveryComplete = 'discovery_complete';

    // --- Scoring (Phase 9) ---
    /**
     * New strongly-matching leads were graded. **Aggregated** — one notification for a batch, with
     * a count, never one per lead (see `AnnounceTopLeadsJob`).
     */
    case TopLeads = 'top_leads';

    // --- Leads Workspace (Phase 10b) ---
    /** A task's reminder came due. */
    case TaskDue = 'task_due';

    /** Somebody put a lead in this member's hands. */
    case LeadAssigned = 'lead_assigned';

    // --- Signals (S3) ---
    /** A watch ran and brought back new signals. */
    case SignalsDetected = 'signals_detected';

    // --- AI Insights (Phase 14) ---
    /**
     * The day's insights are ready: what has gone cold, who is worth a call, and what Alima made
     * of the week's figures. **Aggregated by construction** — one notification per generation run,
     * carrying the counts, never one per insight.
     */
    case InsightsReady = 'insights_ready';

    // --- AI Platform / Billing ---
    /**
     * A plan allowance — AI credits or new companies — is nearly or fully spent, and work is about
     * to start failing. Delivered to Owners and Admins only: it is a billing matter, not
     * everyone's.
     *
     * One case for both metrics, carrying a `metric` in its payload, because a member who has
     * switched off "an allowance is running low" has said the same thing about both and would not
     * thank us for a second switch. The value stays `credit_low` — it is written into every
     * notification row already stored, and renaming it would orphan them (Phase 16).
     */
    case CreditLow = 'credit_low';

    // --- Billing (Phase 19b) ---
    /**
     * A payment failed.
     *
     * The gap `billing-plans.md` recorded: Stripe's own dunning email was the only signal, so a
     * team whose card had expired found out from a third party or not at all. Owners and Admins
     * only, and **not digestible** — Stripe retries for a fortnight and then cancels, and a notice
     * that arrives in tomorrow's round-up has spent a day of that window.
     */
    case PaymentFailed = 'payment_failed';

    // --- Compliance (Phase 19b) ---
    /**
     * A lawful-basis assessment is due for review.
     *
     * The inverted gap `compliance-privacy.md` recorded: the compliance screen says so and the
     * discovery gate closes, but nothing *tells* anybody — so a team that has stopped visiting the
     * compliance centre discovers it when sourcing stops. Owners and Admins, because it is a legal
     * record only they can sign.
     */
    case AssessmentReviewDue = 'assessment_review_due';

    // --- Admin (Phase 19b) ---
    /**
     * Something the platform wants every affected tenant to know: maintenance, a price change, a
     * regulatory note.
     *
     * **Aggregated by construction** — one per team, never one per member per team (the
     * `AnnounceTopLeadsJob` shape). Not configurable: a tenant cannot switch off being told that we
     * are taking the product down on Sunday.
     */
    case Announcement = 'announcement';

    // --- Notifications itself ---
    /** The daily or weekly round-up. Never itself digestible, for obvious reasons. */
    case Digest = 'digest';

    public function label(): string
    {
        return match ($this) {
            self::DiscoveryComplete => 'A lead search finishes',
            self::TopLeads => 'New strong matches',
            self::TaskDue => 'A task reminder comes due',
            self::LeadAssigned => 'A lead is assigned to me',
            self::SignalsDetected => 'New signals arrive',
            self::InsightsReady => 'The daily insights are ready',
            self::CreditLow => 'An allowance is running low',
            self::PaymentFailed => 'A payment fails',
            self::AssessmentReviewDue => 'A compliance review falls due',
            self::Announcement => 'Platform announcements',
            self::Digest => 'The round-up',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::DiscoveryComplete => 'When a search you started has finished looking.',
            self::TopLeads => 'When Alima grades new companies as a strong fit. One message per batch, not per lead.',
            self::TaskDue => 'When something you asked to be reminded about is due.',
            self::LeadAssigned => 'When a colleague gives you a lead to work.',
            self::SignalsDetected => 'When a watch finds a new trigger event.',
            self::InsightsReady => 'Each morning: who is going cold, and who is worth a call today.',
            self::CreditLow => 'Owners and admins only — work stops when a plan allowance runs out.',
            self::PaymentFailed => 'Owners and admins only — a card was declined and the subscription is at risk.',
            self::AssessmentReviewDue => 'Owners and admins only — your lawful-basis record needs reviewing, and lead discovery stops without it.',
            self::Announcement => 'Maintenance, changes and notices from us. Cannot be switched off.',
            self::Digest => 'Everything that happened, in one message.',
        };
    }

    /**
     * How the preference screen groups the types.
     */
    public function group(): NotificationGroup
    {
        return match ($this) {
            self::DiscoveryComplete, self::TopLeads, self::SignalsDetected => NotificationGroup::Finding,
            self::TaskDue, self::LeadAssigned => NotificationGroup::MyWork,
            self::CreditLow, self::PaymentFailed, self::AssessmentReviewDue, self::Announcement => NotificationGroup::Account,
            // With the digest rather than with Finding: both are a round-up a member reads once a
            // day, and both are the kind of thing somebody switches off together.
            self::InsightsReady, self::Digest => NotificationGroup::Summary,
        };
    }

    /**
     * The channels this type uses when the member hasn't said otherwise.
     *
     * @return list<NotificationChannel>
     */
    public function defaultChannels(): array
    {
        return match ($this) {
            // About *their own* work and time-sensitive: worth an email and a buzz.
            self::TaskDue, self::LeadAssigned => [
                NotificationChannel::InApp,
                NotificationChannel::Mail,
                NotificationChannel::Push,
            ],
            // Money, and the work stops when it runs out.
            //
            // The same two channels for a failed payment and a due assessment, for the same
            // reason: both end with the product refusing to do something, and both have a window
            // in which somebody can prevent that. An in-app badge alone would be seen by whoever
            // happened to log in.
            self::CreditLow, self::PaymentFailed, self::AssessmentReviewDue => [
                NotificationChannel::InApp,
                NotificationChannel::Mail,
            ],
            // In-app only. An announcement is us talking, not something the tenant has to act on
            // today, and emailing every tenant on every notice is how we become a filter rule.
            self::Announcement => [NotificationChannel::InApp],
            // The member asked for it explicitly by turning it on.
            self::Digest => [NotificationChannel::Mail],
            // Useful, not urgent. In-app by default; an email per finished search would train
            // people to filter us, and then they would miss the one above.
            self::DiscoveryComplete, self::TopLeads, self::SignalsDetected => [
                NotificationChannel::InApp,
                NotificationChannel::Push,
            ],
            // A daily email nobody asked for is the fastest way to be filtered — and then the
            // task reminder above goes to the same folder. So the insights arrive in the app and
            // on the phone, and a member who wants them in their inbox turns email on.
            self::InsightsReady => [
                NotificationChannel::InApp,
                NotificationChannel::Push,
            ],
        };
    }

    /**
     * Whether this type may be rolled into the digest instead of arriving on its own.
     */
    public function isDigestible(): bool
    {
        return match ($this) {
            // Reminders and assignments are about *now*; holding one until 8am tomorrow would make
            // the reminder useless and the assignment rude.
            // A failed payment and a due assessment both have a clock on them: Stripe retries for
            // a fortnight, and a lapsed assessment stops discovery. Holding either until 8am
            // tomorrow spends a day of a window somebody needed.
            self::TaskDue, self::LeadAssigned, self::CreditLow, self::Digest,
            self::PaymentFailed, self::AssessmentReviewDue => false,
            self::DiscoveryComplete, self::TopLeads, self::SignalsDetected, self::InsightsReady,
            self::Announcement => true,
        };
    }

    /**
     * Whether only Owners and Admins should receive it. Nothing else in the module decides this, so
     * a new type says here whether it is everyone's business.
     */
    public function isAdminOnly(): bool
    {
        return in_array($this, [
            self::CreditLow,
            // Money, and only an Owner or Admin can act on it.
            self::PaymentFailed,
            // A legal record, signed by a named person. A Member cannot complete it.
            self::AssessmentReviewDue,
        ], strict: true);
    }

    /**
     * Whether a member may switch the type off. The digest is the one that cannot be *configured*
     * here — it is switched on and off by its own frequency setting, and showing it twice would let
     * a member set a frequency and still receive nothing.
     */
    public function isConfigurable(): bool
    {
        return ! in_array($this, [
            self::Digest,
            // A tenant cannot switch off being told we are taking the product down on Sunday. The
            // operator's own `announcements.enabled` kill switch is the only off there is.
            self::Announcement,
        ], strict: true);
    }

    /**
     * The types a member can set preferences for, in the order the screen shows them.
     *
     * @return list<self>
     */
    public static function configurable(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $type): bool => $type->isConfigurable()));
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
