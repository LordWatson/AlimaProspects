<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Enums;

/**
 * What an outbound webhook can be about (PL7).
 *
 * The value is the **public name of the event** and is part of a contract with somebody else's
 * Zapier zap, so these strings are frozen in a way the rest of the enums here are not: renaming one
 * silently stops every subscriber's automation. Dotted, because that is the shape every integration
 * platform's own event names take and a customer filtering on `lead.*` should be able to.
 *
 * Five, and each one is a thing a person outside Alima would build an automation on. Deliberately
 * **not** here: anything about a run, a job or an allowance. A webhook is for the customer's own
 * workflow, and "a discovery run finished" is a fact about our scheduler.
 */
enum WebhookEvent: string
{
    /** A company was added to the team's list, however it was found. */
    case LeadCreated = 'lead.created';

    /** A lead was re-scored — fit, confidence or the why-now line changed. */
    case LeadUpdated = 'lead.updated';

    /** A trigger event was matched for the team (Signals). */
    case SignalDetected = 'signal.detected';

    /** Contact details were found or verified for a lead (Enrichment). */
    case ContactEnriched = 'contact.enriched';

    /** A record was pushed to the team's CRM. */
    case LeadSentToCrm = 'lead.sent_to_crm';

    public function label(): string
    {
        return match ($this) {
            self::LeadCreated => 'New company found',
            self::LeadUpdated => 'Company re-scored',
            self::SignalDetected => 'Trigger event detected',
            self::ContactEnriched => 'Contact details found',
            self::LeadSentToCrm => 'Record sent to CRM',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::LeadCreated => 'Sent once for each company added to your list.',
            self::LeadUpdated => 'Sent when a company\'s fit, confidence or why-now changes.',
            self::SignalDetected => 'Sent for each trigger event a watch finds.',
            self::ContactEnriched => 'Sent when Alima finds or checks details for a company.',
            self::LeadSentToCrm => 'Sent when Alima pushes a record to your CRM.',
        };
    }

    /**
     * How noisy this event is, as a warning beside the checkbox.
     *
     * Worth saying out loud on the screen rather than in the docs: `lead.created` fires once per
     * company, and a discovery run finds hundreds. A customer whose endpoint is a spreadsheet row
     * append should know that before they tick it, not after.
     */
    public function isHighVolume(): bool
    {
        return match ($this) {
            self::LeadCreated, self::LeadUpdated, self::SignalDetected => true,
            self::ContactEnriched, self::LeadSentToCrm => false,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
