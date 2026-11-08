<?php

declare(strict_types=1);

namespace App\Modules\Billing\Enums;

use App\Modules\Core\Enums\Capability;

/**
 * The things Alima meters. Each one has an authoritative ledger elsewhere in the app — this enum is
 * what lets Billing talk about all of them without caring which table they come from.
 *
 * **PL4 adds six, and they differ from the first three in one way that matters.** Leads, AI credits
 * and signal searches are *recorded by the work itself*: a discovery run writes
 * `discovery_runs.leads_created` because that is what it did. A contact reveal records nothing but
 * the fact that somebody clicked, so those six read `usage_ledger` — a table that exists precisely
 * because they had no table. The shape is identical (append-only, read by aggregate, never
 * incremented); only the source differs, which is the whole point of this enum.
 *
 * **An absent allowance means zero for the six, and unlimited for the three.** That asymmetry is
 * deliberate and is the `StubSignalQuotaSource` argument again: leads and credits are things the
 * product does, so a plan that does not mention them is a plan with no ceiling. A reveal costs us
 * money per unit, so a plan that does not mention it includes **none** — and a plan config that
 * forgot one must fail closed rather than hand out an unmetered vendor bill.
 *
 * **Units matter here more than anywhere else in the codebase.** A meter row, a plan allowance and
 * everything this enum returns are in the metric's own unit: whole leads for `Leads`, whole credits
 * for `AiCredits`. The AI ledger stores cost in *micro-units*, so credits cross that boundary
 * through {@see self::fromMicros()} / {@see self::toMicros()} and nowhere else.
 */
enum BillingMetric: string
{
    /** New companies persisted by a discovery run (`discovery_runs.leads_created`). */
    case Leads = 'leads';

    /** AI spend (`ai_requests.cost_micros`), expressed in credits. */
    case AiCredits = 'ai_credits';

    /**
     * Paid signal searches (`signal_runs.queries_count`), added in S16.
     *
     * The third metric, and the one that makes this enum's own argument concrete: a Serper query is
     * billed to us the moment a watch asks, so a plan that does not bound them is a plan a team can
     * run a four-figure bill on over a weekend. It is metered here — rather than only guarded
     * inside Signals — because a meter row survives the retention sweep that prunes `signal_runs`,
     * which is what makes the month's cost answerable after the fact.
     *
     * **A preview's searches are deliberately not counted here.** They have their own ledger and
     * their own ceiling (`signal_previews`, `SignalSpendGuard::assertPreviewQueriesRemain()`),
     * because a run is a standing instruction a team configured and a preview is a button somebody
     * is pressing right now — folding them together would let an afternoon of iterating on wording
     * eat a month of scheduled coverage.
     */
    case SignalQueries = 'signal_queries';

    // --- PL4: the contact-data meters. Read `usage_ledger`; see the class note. -------------

    /** A paid people-find: "who are the decision-makers at this company" (PL3). */
    case PeopleSearch = 'people_search';

    /** A member spending to see an address a vendor was paid for. */
    case EmailReveal = 'email_reveal';

    /** The same for a number. Priced apart from an address because a mobile costs us far more. */
    case PhoneReveal = 'phone_reveal';

    /** A paid verification of one address. The cheap one, and the one run most often. */
    case EmailVerification = 'email_verification';

    /** Rows taken out as CSV (PL6). Metered because an export is the whole list leaving. */
    case ExportRows = 'export_rows';

    /** Records pushed to a CRM (PL7). */
    case CrmSync = 'crm_sync';

    public function label(): string
    {
        return match ($this) {
            self::Leads => 'Companies found',
            self::AiCredits => 'AI credits',
            self::SignalQueries => 'Signal searches',
            self::PeopleSearch => 'Decision-maker searches',
            self::EmailReveal => 'Email reveals',
            self::PhoneReveal => 'Phone reveals',
            self::EmailVerification => 'Email checks',
            self::ExportRows => 'Exported rows',
            self::CrmSync => 'CRM syncs',
        };
    }

    /**
     * Whether this metric's consumption is recorded in `usage_ledger` rather than by the work
     * itself (PL4).
     *
     * The one place the two kinds of metric are told apart, so nothing else has to know: a reader
     * asks the enum which table to aggregate, exactly as it already asks for the allowance key.
     */
    public function isLedgered(): bool
    {
        return match ($this) {
            self::Leads, self::AiCredits, self::SignalQueries => false,
            self::PeopleSearch, self::EmailReveal, self::PhoneReveal,
            self::EmailVerification, self::ExportRows, self::CrmSync => true,
        };
    }

    /**
     * What runs out when the allowance does — the sentence a member needs to understand the stop.
     */
    public function exhaustedDescription(): string
    {
        return match ($this) {
            self::Leads => 'New searches stop until the allowance resets or the plan changes.',
            self::AiCredits => 'Scoring, drafting and insights stop until the allowance resets or the plan changes.',
            self::SignalQueries => 'Watches stop looking for trigger events until the allowance resets or the plan changes.',
            self::PeopleSearch => 'Alima stops looking for new decision-makers until the allowance resets, you top up, or the plan changes.',
            self::EmailReveal => 'Addresses Alima found stay hidden until the allowance resets, you top up, or the plan changes.',
            self::PhoneReveal => 'Numbers Alima found stay hidden until the allowance resets, you top up, or the plan changes.',
            self::EmailVerification => 'Addresses stop being checked until the allowance resets or the plan changes.',
            self::ExportRows => 'Exports stop until the allowance resets or the plan changes.',
            self::CrmSync => 'Pushing records to your CRM stops until the allowance resets or the plan changes.',
        };
    }

    /**
     * The capability a team must have for this metric to mean anything to them, or null when every
     * team has it.
     *
     * **For the screen, not for the meter** (`/code-review`). `usage_meters` keeps a row per metric
     * per team per month whatever their plan — it is a billing record and a gap in one is worse than
     * a zero — but a Free team has no business reading "Signal searches 0 / 100" and "watches stop
     * looking for trigger events" about a feature their plan does not include at all. The figures
     * for those plans exist only as what an operator's override would entitle a pilot to
     * (`config/billing.php`), and the screen could not make that distinction on its own.
     *
     * On the enum so a fourth metric cannot be added without answering the question.
     */
    public function requiresCapability(): ?Capability
    {
        return match ($this) {
            self::Leads, self::AiCredits => null,
            self::SignalQueries => Capability::Signals,
            // The four contact meters all belong to the one capability a plan withholds.
            self::PeopleSearch, self::EmailReveal, self::PhoneReveal,
            self::EmailVerification => Capability::Enrichment,
            self::ExportRows => Capability::CsvExport,
            // No capability of its own: PL7 decides whether CRM sync is a plan boundary, and
            // inventing one here would put a `Capability` case in the enum that nothing enforces,
            // which is the one rule that enum has.
            self::CrmSync => null,
        };
    }

    /**
     * How a sentence names what has run out — "Your search allowance has run out".
     *
     * On the enum rather than in `NotificationPresenter`, where it used to be a two-way ternary:
     * adding a third metric there would have silently described signal searches as AI credit, which
     * is the failure mode a `match` over the enum cannot have.
     */
    public function subjectLabel(): string
    {
        return match ($this) {
            self::Leads => 'Your search allowance',
            self::AiCredits => 'AI credit',
            self::SignalQueries => 'Your signal allowance',
            self::PeopleSearch => 'Your decision-maker searches',
            self::EmailReveal => 'Your email reveals',
            self::PhoneReveal => 'Your phone reveals',
            self::EmailVerification => 'Your email checks',
            self::ExportRows => 'Your export allowance',
            self::CrmSync => 'Your CRM sync allowance',
        };
    }

    /**
     * The config key under `billing.plans.<plan>` holding this metric's monthly allowance.
     *
     * Signal searches are nested under the plan's `signals` block rather than sitting beside
     * `leads` and `credits`, because the other seven figures a plan sets for Signals live there and
     * one of them split out would be the one that drifts (`SignalQuotaSource`).
     */
    public function allowanceKey(): string
    {
        return match ($this) {
            self::Leads => 'leads',
            self::AiCredits => 'credits',
            self::SignalQueries => 'signals.monthly_source_queries',
            // Nested under the plan's own `contacts` block, for the reason the signals one is:
            // the six figures belong together, and one split out beside `leads` and `credits`
            // would be the one that drifts when the others are tuned.
            self::PeopleSearch => 'contacts.people_search',
            self::EmailReveal => 'contacts.email_reveal',
            self::PhoneReveal => 'contacts.phone_reveal',
            self::EmailVerification => 'contacts.email_verification',
            self::ExportRows => 'contacts.export_rows',
            self::CrmSync => 'contacts.crm_sync',
        };
    }

    /**
     * The **whole sentence** a price line is rendered from, as a translation key (S16 brand review).
     *
     * One complete key per metric rather than a shared template with the metric's name interpolated
     * into it, and that is a translation requirement rather than a style preference: a template
     * fixing the order "`:price` per `:count` `:thing`" forces Arabic into English word order, and
     * Arabic needs a different construction per noun — a preposition that agrees, and a noun form
     * that changes with the count (it has a dual as well as plurals). Interpolating a translated
     * noun into a translated sentence makes a correct Arabic rendering impossible for the
     * translator, however well either half is written.
     *
     * Both `:price` and `:count` are offered to every key; the client's `t()` leaves an unused
     * replacement alone, so a metric billed singly simply does not mention the count.
     */
    public function overagePriceLine(): string
    {
        return match ($this) {
            self::Leads => ':price per company',
            self::AiCredits => ':price per :count AI credits',
            self::SignalQueries => ':price per :count signal searches',
            self::PeopleSearch => ':price per decision-maker search',
            self::EmailReveal => ':price per email reveal',
            self::PhoneReveal => ':price per phone reveal',
            self::EmailVerification => ':price per :count email checks',
            self::ExportRows => ':price per :count exported rows',
            self::CrmSync => ':price per :count CRM syncs',
        };
    }

    /**
     * Whether a team can **buy more** of this metric when it runs out (PL4).
     *
     * Only the three that cost us money per unit and that a member hits mid-task. A top-up for
     * leads or signal searches would be selling a standing instruction to spend, which is a plan
     * change rather than a purchase — and a top-up for export rows would be charging for the
     * team's own data on its way out, which is not a product.
     */
    public function isToppable(): bool
    {
        return match ($this) {
            self::EmailReveal, self::PhoneReveal, self::PeopleSearch => true,
            default => false,
        };
    }

    /**
     * How many of the metric one *billed* overage unit covers. Leads are billed singly; credits in
     * thousands, because an invoice line per credit is unreadable.
     */
    public function overageUnit(): int
    {
        return max(1, (int) config('billing.overage.'.$this->value.'.unit', 1));
    }

    /**
     * Credits from the AI ledger's cost micro-units. Only meaningful for {@see self::AiCredits};
     * calling it on leads is a programming error, so it returns the value untouched rather than
     * inventing a conversion.
     */
    public function fromMicros(int $micros): int
    {
        if ($this !== self::AiCredits) {
            return $micros;
        }

        return intdiv($micros, self::microsPerCredit());
    }

    /**
     * The inverse of {@see self::fromMicros()}.
     */
    public function toMicros(int $amount): int
    {
        if ($this !== self::AiCredits) {
            return $amount;
        }

        return $amount * self::microsPerCredit();
    }

    /**
     * Guarded against a zero/negative config value, which would otherwise be a division by zero on
     * every AI call.
     */
    private static function microsPerCredit(): int
    {
        return max(1, (int) config('ai-platform.credits.micros_per_credit', 1_000));
    }
}
