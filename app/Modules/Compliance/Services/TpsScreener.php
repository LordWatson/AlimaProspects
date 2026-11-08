<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Services;

use App\Modules\Compliance\Contracts\TpsScreeningProvider;
use App\Modules\Compliance\Enums\TpsRegister;
use App\Modules\Compliance\Models\TpsCheck;
use App\Modules\Core\Enums\Market;
use App\Modules\Core\Enums\TpsStatus;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\ContactIdentity;
use App\Modules\Enrichment\Contracts\ContactNumberScreener;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\LeadSourcing\Models\Lead;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * TPS/CTPS screening (PL5, `docs/compliance.md` section 3.16) — the real
 * {@see ContactNumberScreener}, bound over Enrichment's no-op by this module's provider.
 *
 * It answers one question and records it in two places, which is the whole design:
 *
 * - **`tps_checks`** is the platform-wide cache. A register charges per lookup and its answer is a
 *   fact about the number rather than about a tenant, so two customers holding the same switchboard
 *   pay for one lookup. Keyed by the same hash `outreach_suppressions` and `lead_contacts` use.
 * - **`lead_contacts.tps_status`** is the read path. A lead screen renders every contact it holds,
 *   and a badge that needed a lookup per row would be an N+1 on the hottest page here.
 *
 * Four rules worth holding on to:
 *
 * 1. **Which register is asked follows the subscriber, not the caller.** A corporate subscriber is
 *    on CTPS and everybody else on TPS ({@see PecrSubscriberType::register()}), so the classifier
 *    decides and this does not take a preference.
 * 2. **Unknown is not clear.** A provider that is unavailable, fails, or answers something this
 *    code does not recognise leaves the number `unchecked`. The contact is still stamped — with
 *    `NotApplicable` for a non-UK number, which is a different thing from unscreened — but nothing
 *    here can produce a `Clear` that a register did not say.
 * 3. **A failure never propagates.** This runs after a reveal the member has already been charged
 *    for. Throwing would roll back nothing and lose them the credit, so a `Throwable` is logged by
 *    id and swallowed, the same way `AttemptRecorder` swallows its own (ADR 0030).
 * 4. **It re-screens rather than ages out.** A verdict older than `compliance.tps.recheck_days` is
 *    replaced; if it cannot be replaced, the old row stands and the contact keeps the stamp it had.
 *    Dropping a stale verdict with nothing to put in its place would turn an outage into a
 *    product-wide "never screened".
 */
final class TpsScreener implements ContactNumberScreener
{
    public function __construct(
        private readonly TpsScreeningProvider $provider,
        private readonly SubscriberClassifier $classifier,
    ) {}

    public function screen(Team $team, LeadContact $contact): void
    {
        try {
            $this->run($contact);
        } catch (Throwable $e) {
            // Ids and the class only. The number is the one thing that must not reach a log.
            Log::channel('compliance')->warning('TPS screening failed.', [
                'team_id' => (int) $team->getKey(),
                'contact_id' => (int) $contact->getKey(),
                'exception' => $e::class,
            ]);
        }
    }

    /**
     * The verdict for one number, from the cache or the register. Public so the export and sync
     * paths that PL6 and PL7 add can ask about a number they are about to hand over, rather than
     * reading `lead_contacts` and trusting a stamp that may be months old.
     */
    public function statusFor(string $phone, TpsRegister $register): TpsStatus
    {
        $digits = ContactIdentity::phone($phone);

        if ($digits === null) {
            return TpsStatus::Unchecked;
        }

        return $this->resolve($digits, $register) ?? TpsStatus::Unchecked;
    }

    private function run(LeadContact $contact): void
    {
        $number = $contact->phoneValue();
        $digits = $number === null ? null : ContactIdentity::phone($number);

        if ($digits === null) {
            return;
        }

        // `loadMissing` rather than a bare `->lead`: this runs from a reveal that did not
        // necessarily eager-load it, and an implicit read would trip `preventLazyLoading`.
        $lead = $contact->loadMissing('lead')->lead;

        $applicable = $this->isUkNumber($digits, $lead);

        if ($applicable === false) {
            // Outside both registers. Stamped, because "outside TPS" is an answer a screen should
            // show rather than leaving a UAE number looking merely unscreened.
            $this->stamp($contact, TpsStatus::NotApplicable);

            return;
        }

        if ($applicable === null) {
            // We cannot tell where the number is. Not screened, not cleared, not stamped.
            return;
        }

        $register = $lead instanceof Lead
            ? $this->classifier->classify($lead)->register()
            : TpsRegister::Tps;

        $status = $this->resolve($digits, $register);

        if ($status !== null) {
            $this->stamp($contact, $status);
        }
    }

    /**
     * The cached verdict if it is fresh, otherwise the register's — or null when nobody can answer.
     */
    private function resolve(string $digits, TpsRegister $register): ?TpsStatus
    {
        $hash = ContactIdentity::hash($digits);

        $cached = TpsCheck::query()
            ->where('phone_hash', $hash)
            ->where('register', $register->value)
            ->first();

        if ($cached instanceof TpsCheck && $cached->isFresh()) {
            return $cached->result;
        }

        if (! $this->provider->isAvailable()) {
            // No licensed provider. A stale cached verdict is still better than nothing — it is
            // what the register last said — and is returned rather than discarded.
            return $cached?->result;
        }

        $answer = $this->provider->screen($digits, $register);

        if (! $answer?->isScreened()) {
            // Null, or something that is not a register's verdict. Either way not an answer, and
            // the previous one stands.
            return $cached?->result;
        }

        // `updateOrCreate` on the unique key: this is a cache, and the history of a register's
        // answers is not a question the product has.
        TpsCheck::query()->updateOrCreate(
            ['phone_hash' => $hash, 'register' => $register->value],
            ['result' => $answer->value, 'provider' => $this->provider->key(), 'checked_at' => Carbon::now()],
        );

        return $answer;
    }

    /**
     * True for a UK number, false for one we can place elsewhere, **null when we cannot tell**.
     *
     * Three answers rather than two, for the reason the rest of this module keeps three: a number
     * with no country is not a number outside the registers. The dialling code is trusted first
     * because it is a fact about the number; the lead's own country is the fallback, which is the
     * same order {@see MarketConsentGate} uses for a jurisdiction.
     */
    private function isUkNumber(string $digits, ?Lead $lead): ?bool
    {
        if (Str::startsWith($digits, '44')) {
            return true;
        }

        $market = Market::forCountry($lead?->country);

        if ($market === Market::Uk) {
            return true;
        }

        if ($market !== null) {
            return false;
        }

        // A bare national number with no country on the lead could be anywhere.
        return null;
    }

    private function stamp(LeadContact $contact, TpsStatus $status): void
    {
        // `forceFill`: neither column is fillable, because a screening verdict is a register's
        // statement and not something a request or an import may assert.
        $contact->forceFill([
            'tps_status' => $status->value,
            'tps_checked_at' => Carbon::now(),
        ])->save();
    }
}
