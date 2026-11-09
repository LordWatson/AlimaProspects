<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Enums;

use App\Modules\LeadsWorkspace\Services\LeadCsvExporter;
use App\Modules\LeadsWorkspace\Services\LeadCsvImporter;

/**
 * What an export puts in the file (PL6).
 *
 * A preset is **a list of column keys**, nothing more — which is what makes {@see self::Custom}
 * nearly free: the member's ticked boxes are a list of the same keys, validated against
 * {@see LeadCsvExporter::ALL_COLUMNS}. Every other difference between the presets is a consequence
 * of which keys they name.
 *
 * {@see self::LeadsOnly} is **today's column set, unchanged, in today's order**, and it must stay
 * that way: it is the set {@see LeadCsvImporter} reads back, so
 * an export edited in a spreadsheet and imported again lands on the same leads rather than
 * duplicating them. That round trip is the most common reason a team exports at all, and a column
 * added to the front of it would silently break it.
 *
 * {@see self::CrmReady} is the one preset that changes the **shape** rather than the columns: one
 * row per contact, with the company columns repeated, because that is what a CRM import expects and
 * a team otherwise does it with a spreadsheet formula.
 */
enum ExportPreset: string
{
    /** One row per lead, in the column set the importer reads back. */
    case LeadsOnly = 'leads_only';

    /** The same, plus why-now, confidence, evidence, the screening verdicts and a link back. */
    case LeadsContacts = 'leads_contacts';

    /** One row per contact — company columns repeated — for a CRM's own importer. */
    case CrmReady = 'crm_ready';

    /** Whatever the member ticked. */
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::LeadsOnly => 'Leads (imports back into Alima)',
            self::LeadsContacts => 'Leads with contacts and evidence',
            self::CrmReady => 'CRM-ready (one row per contact)',
            self::Custom => 'Choose the columns',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::LeadsOnly => 'The company, its score and its best contact. This is the file Alima can read back.',
            self::LeadsContacts => 'Everything above, plus why now, the confidence, the top three pieces of evidence and the do-not-call verdicts.',
            self::CrmReady => 'A row for every contact, with the company details repeated on each one.',
            self::Custom => 'Pick exactly the columns you need.',
        };
    }

    /**
     * Whether a lead with three contacts becomes three rows.
     *
     * A lead with **no** contacts is still one row in every preset: a company worth exporting is
     * worth exporting before anybody has found a person there.
     */
    public function rowPerContact(): bool
    {
        return $this === self::CrmReady;
    }

    /**
     * The columns, in order. Empty for {@see self::Custom}, whose columns come from the request.
     *
     * @return list<string>
     */
    public function columns(): array
    {
        return match ($this) {
            self::LeadsOnly => LeadCsvExporter::COLUMNS,
            self::LeadsContacts => [
                ...LeadCsvExporter::COLUMNS,
                'timing_score',
                'confidence',
                'why_now_summary',
                'evidence_1',
                'evidence_2',
                'evidence_3',
                'contact_email_status',
                'contact_phone_e164',
                'contact_phone_type',
                'contact_tps_status',
                'pecr_subscriber_type',
                'lead_url',
            ],
            // Deliberately a different order: a CRM importer's first question is who the person
            // is, and the company is context for them rather than the other way round.
            self::CrmReady => [
                'contact_name',
                'contact_title',
                'contact_email',
                'contact_email_status',
                'contact_phone_e164',
                'contact_phone_type',
                'contact_tps_status',
                'company_name',
                'website',
                'company_number',
                'country',
                'locality',
                'region',
                'postal_code',
                'industry',
                'employee_range',
                'grade',
                'score',
                'confidence',
                'why_now_summary',
                'pecr_subscriber_type',
                'stage',
                'tags',
                'assignees',
                'lead_url',
            ],
            self::Custom => [],
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $preset): string => $preset->value, self::cases());
    }
}
