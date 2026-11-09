<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Services;

use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadSourcing\Models\LeadEvidence;

/**
 * The note Alima attaches to a record in somebody else's CRM (PL7).
 *
 * This is the only piece of **prose** the integration framework produces, and the one place where
 * the product is read by somebody who has never seen it: an account manager opens an organisation
 * in Pipedrive and finds a paragraph from us. So it is composed once, here, rather than by each
 * connector — two connectors would be two voices, and the one with fewer customers would be the one
 * that was never corrected.
 *
 * It says three things and nothing else:
 *
 * 1. **Why now**, if PL1 has generated a sentence. Never invented here: the summary is only ever
 *    produced from supplied evidence (ADR 0028), and a note that filled the gap with "this company
 *    looks like a good fit" would be the one claim in the product with nothing behind it.
 * 2. **The evidence**, as dated, sourced bullets with their links. The dates are the point — an
 *    account manager needs to know whether the funding round was last week or in 2019 — and a
 *    **disputed** item is dropped rather than shown, because a member has already said it is wrong
 *    and a CRM note is beyond anybody's reach to correct afterwards.
 * 3. **A link back**, so the CRM record can be read against the whole lead rather than this
 *    snapshot of it.
 *
 * Plain text, not HTML or markdown: every CRM renders notes differently and at least one strips
 * tags, so the formatting that survives everywhere is a blank line and a dash.
 */
final class CrmNoteComposer
{
    /** Enough to explain the lead; past that it is a wall of text in somebody's sidebar. */
    private const int MAX_EVIDENCE = 5;

    /**
     * @param  iterable<LeadEvidence>  $evidence  newest first; the caller decides the order
     */
    public function compose(Lead $lead, iterable $evidence): string
    {
        $lines = ['Why this company, from Alima'];

        if (is_string($lead->why_now_summary) && $lead->why_now_summary !== '') {
            $lines[] = '';
            $lines[] = $lead->why_now_summary;
        }

        $bullets = $this->bullets($evidence);

        if ($bullets !== []) {
            $lines[] = '';
            $lines[] = 'Evidence:';
            $lines = [...$lines, ...$bullets];
        }

        $lines[] = '';
        $lines[] = 'Full lead: '.route('leads.show', ['lead' => $lead->getKey()]);

        return implode("\n", $lines);
    }

    /**
     * @param  iterable<LeadEvidence>  $evidence
     * @return list<string>
     */
    private function bullets(iterable $evidence): array
    {
        $bullets = [];

        foreach ($evidence as $item) {
            if ($item->is_disputed) {
                continue;
            }

            // The date the thing happened where we know it, and the date we found it otherwise.
            // Labelled neither way: "2026-03-04 — Raised a Series A" is read as when it happened,
            // which is what the reader wants, and a capture date is the honest stand-in.
            $date = ($item->observed_at ?? $item->captured_at)->toDateString();

            $bullet = '- '.$date.' — '.$item->title;

            $source = $item->source_label ?? $item->source;

            if ($source !== '') {
                $bullet .= ' ('.$source.')';
            }

            if (is_string($item->source_url) && $item->source_url !== '') {
                $bullet .= ' '.$item->source_url;
            }

            $bullets[] = $bullet;

            if (count($bullets) >= self::MAX_EVIDENCE) {
                break;
            }
        }

        return $bullets;
    }
}
