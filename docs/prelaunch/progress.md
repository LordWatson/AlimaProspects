# Pre-launch progress

One line per phase of `pre-launch.md`, appended as each lands. Read `docs/prelaunch/recon.md` before
starting any phase; read ADR 0026 for the four places the plan loses to this repository.

| Phase | Name                                            | Status      | Date       | Notes                                                                                                                                                                                                                                                                                                                                                                                                                                                                          |
| ----- | ----------------------------------------------- | ----------- | ---------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| PL0   | Recon, alignment and vendor decisions           | **Done**    | 2026-11-08 | `docs/prelaunch/recon.md` (inventory, gap table, binding adapt notes), ADR 0026 (scope + 4 conflicts), ADR 0027 (vendors, `DECISION PENDING`). No app code.                                                                                                                                                                                                                                                                                                                    |
| PL1   | Lead evidence and "why now"                     | **Done**    | 2026-11-13 | `lead_evidence` + feedback, the `LeadConfidence` calculator, signal→evidence carry-over, `evidence:backfill`, the `lead.why_now` prompt, web + mobile UI. ADR 0028.                                                                                                                                                                                                                                                                                                            |
| PL2   | Contact model and enrichment provider contracts | **Done**    | 2026-11-14 | Four capability contracts + registry + DTOs + four fakes; `lead_contacts` extended with identity, provenance, reveals and its own state; masking by who-was-paid; `ContactIdentity` shared with the suppression list. **One** experimental adapter (ZeroBounce verifier, disabled) — no finder adapter, because no finder's response shape was confirmable. ADR 0029.                                                                                                          |
| PL3   | Waterfall enrichment orchestration              | **Done**    | 2026-11-15 | Runs + append-only attempts, the stop-when-good-enough waterfall, rate limiter + breaker skips, reserve/commit around the spend, reveal (web + mobile), bulk Find, admin per-vendor cost-per-hit. ADR 0030.                                                                                                                                                                                                                                                                    |
| PL4   | Usage ledger, plan gating and top-ups           | **Done**    | 2026-11-08 | `usage_ledger` with reserve/commit/release serialised on the `usage_meters` row; six new `BillingMetric` cases with a `contacts` block per plan (absent = **none**); the fifth seam bound outside `enforce_plans`; Stripe top-ups (option index, never a price) granted on the paid webhook, idempotent on the event id; `MarginGuard` on phone reveals; enrichment cost in the platform margin rollup. **No** `team_allowances`, no `can-*` gates, no rollover job. ADR 0031. |     |
| PL5   | Compliance core                                 | Not started | —          | Extends `outreach_suppressions`; does not add a table.                                                                                                                                                                                                                                                                                                                                                                                                                         |
| PL6   | CSV export                                      | Not started | —          | Extends `LeadCsvExporter`.                                                                                                                                                                                                                                                                                                                                                                                                                                                     |
| PL7   | Integration framework and outbound webhooks     | Not started | —          | Creates `app/Modules/Integrations`.                                                                                                                                                                                                                                                                                                                                                                                                                                            |
| PL8   | Pipedrive sync                                  | Not started | —          |                                                                                                                                                                                                                                                                                                                                                                                                                                                                                |
| PL9   | HubSpot sync                                    | Not started | —          |                                                                                                                                                                                                                                                                                                                                                                                                                                                                                |
| PL10  | Free sample report                              | Not started | —          |                                                                                                                                                                                                                                                                                                                                                                                                                                                                                |
| PL11  | Trust centre, legal pages, Article 14 notices   | Not started | —          |                                                                                                                                                                                                                                                                                                                                                                                                                                                                                |
| PL12  | Hardening, QA and go/no-go                      | Not started | —          | Owns the queue-splitting decision deferred by ADR 0026 §2a.                                                                                                                                                                                                                                                                                                                                                                                                                    |

## Flags

Nine Pennant flags are planned (plan Appendix A). **Each is removed once its feature is live**
(ADR 0026 §4). None exists yet.

| Flag                         | Phase | State                                                |
| ---------------------------- | ----- | ---------------------------------------------------- |
| `prelaunch.why-now`          | PL1   | **defined, off by default** (`PRELAUNCH_WHY_NOW`)    |
| `prelaunch.enrichment`       | PL3   | **defined, off by default** (`PRELAUNCH_ENRICHMENT`) |
| `prelaunch.export`           | PL6   | not created                                          |
| `prelaunch.webhooks`         | PL7   | not created                                          |
| `prelaunch.pipedrive`        | PL8   | not created                                          |
| `prelaunch.hubspot`          | PL9   | not created                                          |
| `prelaunch.sample-report`    | PL10  | not created                                          |
| `prelaunch.trust`            | PL11  | not created                                          |
| `prelaunch.article14-notify` | PL11  | not created                                          |

## Found while building, not fixed

- **`invoice.payment_failed` has never reached the app** (found in PL4). `Billing\Listeners\SyncSubscriptionFromStripe`
  listens on Cashier's `WebhookHandled`, which Cashier fires **only** for the event types it has a
  handler method for — and it has no `handleInvoicePaymentFailed`. So the "your card was declined"
  notification to Owners and Admins, added in Phase 19b, has never fired; Stripe's own dunning email
  is still the only signal. The fix is a **second** listener on `WebhookReceived` (PL4's
  `GrantTopUpFromStripe` is the pattern), not a change to this one — moving it would make it run
  before Cashier has synced the subscription row it re-reads. One small listener plus a test; PL12
  unless it comes up sooner.

## Open items for Alex

Carried from `pre-launch.md` §2; the Stripe top-up prices are now the only one that gates a shipped feature (there is simply no “Buy more” button until they exist — by design, see ADR 0031 §8).

- [ ] Enrichment vendors chosen, with **written confirmation that embedding in a SaaS product is
      permitted**, per-call costs in micros and rate limits → completes ADR 0027 §2. _(Not Apollo.)_
- [ ] Vendor accounts and API keys in production `.env`.
- [ ] TPS/CTPS screening access, with a licence that permits automated screening in a product (PL5).
- [ ] UAE advice: whether TDRA's DNCR applies to customers' outreach, and what PDPL requires for B2B
      prospecting data → PL5's ADR.
- [ ] Pipedrive Developer Hub app (OAuth client id/secret, redirect URL) (PL8).
- [ ] HubSpot developer account and public app (PL9).
- [ ] Cloudflare Turnstile site key + secret (PL10).
- [ ] **Stripe top-up products/prices (PL4)** — three one-off GBP prices: +250 email reveals, +50 phone reveals, +150 decision-maker searches. Then set `STRIPE_PRICE_TOPUP_EMAIL_REVEAL`, `STRIPE_PRICE_TOPUP_PHONE_REVEAL`, `STRIPE_PRICE_TOPUP_PEOPLE_SEARCH`.
- [ ] **Confirm the PL4 contact allowances** per plan. The figures shipped are `pre-launch.md`'s own proposal for Starter (email 250 / phone 25 / people 150 / checks 500 / export 2,000 / CRM 1,000) scaled up and down for the other four; all are `env()`-overridable in `config/billing.php` under `plans.*.contacts`.
- [ ] **A two-connection MySQL concurrency test for `UsageAllowance::reserve()`** (PL12), in the
      style of `tests/Feature/Signals/SignalsMysqlDialectTest.php`. `lockForUpdate()` is a no-op on
      SQLite, so the suite cannot prove the half of the guarantee that depends on it — see ADR 0031
      §2 on InnoDB's REPEATABLE READ snapshot. The code is right; nothing would catch it going
      wrong.
- [ ] **Refunds and disputes do not revoke a top-up** (ADR 0031's not-built table). Nothing listens
      for `charge.refunded` or `charge.dispute.created`, and `usage_ledger.quantity` is unsigned, so
      a claw-back needs a signed adjustment or a `Revoke` direction. Units come back by hand until
      then.
- [ ] **Arabic copy for PL4's 26 new strings needs brand-owner sign-off** (`brand-tokens.md` §7).
      They are in `lang/ar.json` as a translator's starting point, not approved copy.
- [ ] **Decide whether top-ups should roll over.** They currently lapse with the period they are granted in (ADR 0031 §7). Carrying them forward needs consumption tracked per grant.
- [ ] Legal review of the trust centre, privacy notice, DPA, Article 14 notice and lawful-basis
      wording (PL11).
- [ ] Hetzner data-centre region confirmed for the hosting statement (PL11).
