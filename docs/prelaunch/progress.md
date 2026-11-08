# Pre-launch progress

One line per phase of `pre-launch.md`, appended as each lands. Read `docs/prelaunch/recon.md` before
starting any phase; read ADR 0026 for the four places the plan loses to this repository.

| Phase | Name                                            | Status      | Date       | Notes                                                                                                                                                               |
| ----- | ----------------------------------------------- | ----------- | ---------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| PL0   | Recon, alignment and vendor decisions           | **Done**    | 2026-11-08 | `docs/prelaunch/recon.md` (inventory, gap table, binding adapt notes), ADR 0026 (scope + 4 conflicts), ADR 0027 (vendors, `DECISION PENDING`). No app code.         |
| PL1   | Lead evidence and "why now"                     | **Done**    | 2026-11-13 | `lead_evidence` + feedback, the `LeadConfidence` calculator, signal→evidence carry-over, `evidence:backfill`, the `lead.why_now` prompt, web + mobile UI. ADR 0028. |
| PL2   | Contact model and enrichment provider contracts | Not started | —          | Blocked only for a _real_ adapter (ADR 0027 §2).                                                                                                                    |
| PL3   | Waterfall enrichment orchestration              | Not started | —          |                                                                                                                                                                     |
| PL4   | Usage ledger, plan gating and top-ups           | Not started | —          |                                                                                                                                                                     |
| PL5   | Compliance core                                 | Not started | —          | Extends `outreach_suppressions`; does not add a table.                                                                                                              |
| PL6   | CSV export                                      | Not started | —          | Extends `LeadCsvExporter`.                                                                                                                                          |
| PL7   | Integration framework and outbound webhooks     | Not started | —          | Creates `app/Modules/Integrations`.                                                                                                                                 |
| PL8   | Pipedrive sync                                  | Not started | —          |                                                                                                                                                                     |
| PL9   | HubSpot sync                                    | Not started | —          |                                                                                                                                                                     |
| PL10  | Free sample report                              | Not started | —          |                                                                                                                                                                     |
| PL11  | Trust centre, legal pages, Article 14 notices   | Not started | —          |                                                                                                                                                                     |
| PL12  | Hardening, QA and go/no-go                      | Not started | —          | Owns the queue-splitting decision deferred by ADR 0026 §2a.                                                                                                         |

## Flags

Nine Pennant flags are planned (plan Appendix A). **Each is removed once its feature is live**
(ADR 0026 §4). None exists yet.

| Flag                         | Phase | State                                             |
| ---------------------------- | ----- | ------------------------------------------------- |
| `prelaunch.why-now`          | PL1   | **defined, off by default** (`PRELAUNCH_WHY_NOW`) |
| `prelaunch.enrichment`       | PL3   | not created                                       |
| `prelaunch.export`           | PL6   | not created                                       |
| `prelaunch.webhooks`         | PL7   | not created                                       |
| `prelaunch.pipedrive`        | PL8   | not created                                       |
| `prelaunch.hubspot`          | PL9   | not created                                       |
| `prelaunch.sample-report`    | PL10  | not created                                       |
| `prelaunch.trust`            | PL11  | not created                                       |
| `prelaunch.article14-notify` | PL11  | not created                                       |

## Open items for Alex

Carried from `pre-launch.md` §2; none is blocking before PL4.

- [ ] Enrichment vendors chosen, with **written confirmation that embedding in a SaaS product is
      permitted**, per-call costs in micros and rate limits → completes ADR 0027 §2. _(Not Apollo.)_
- [ ] Vendor accounts and API keys in production `.env`.
- [ ] TPS/CTPS screening access, with a licence that permits automated screening in a product (PL5).
- [ ] UAE advice: whether TDRA's DNCR applies to customers' outreach, and what PDPL requires for B2B
      prospecting data → PL5's ADR.
- [ ] Pipedrive Developer Hub app (OAuth client id/secret, redirect URL) (PL8).
- [ ] HubSpot developer account and public app (PL9).
- [ ] Cloudflare Turnstile site key + secret (PL10).
- [ ] Stripe top-up products/prices (PL4).
- [ ] Legal review of the trust centre, privacy notice, DPA, Article 14 notice and lawful-basis
      wording (PL11).
- [ ] Hetzner data-centre region confirmed for the hosting statement (PL11).
