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

    // --- AI Platform / Billing ---
    /**
     * The team's AI allowance is nearly or fully spent, and work is about to start failing.
     * Delivered to Owners and Admins only — it is a billing matter, not everyone's.
     */
    case CreditLow = 'credit_low';

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
            self::CreditLow => 'AI credit is running low',
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
            self::CreditLow => 'Owners and admins only — AI work stops when the allowance runs out.',
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
            self::CreditLow => NotificationGroup::Account,
            self::Digest => NotificationGroup::Summary,
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
            self::CreditLow => [
                NotificationChannel::InApp,
                NotificationChannel::Mail,
            ],
            // The member asked for it explicitly by turning it on.
            self::Digest => [NotificationChannel::Mail],
            // Useful, not urgent. In-app by default; an email per finished search would train
            // people to filter us, and then they would miss the one above.
            self::DiscoveryComplete, self::TopLeads, self::SignalsDetected => [
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
            self::TaskDue, self::LeadAssigned, self::CreditLow, self::Digest => false,
            self::DiscoveryComplete, self::TopLeads, self::SignalsDetected => true,
        };
    }

    /**
     * Whether only Owners and Admins should receive it. Nothing else in the module decides this, so
     * a new type says here whether it is everyone's business.
     */
    public function isAdminOnly(): bool
    {
        return $this === self::CreditLow;
    }

    /**
     * Whether a member may switch the type off. The digest is the one that cannot be *configured*
     * here — it is switched on and off by its own frequency setting, and showing it twice would let
     * a member set a frequency and still receive nothing.
     */
    public function isConfigurable(): bool
    {
        return $this !== self::Digest;
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
