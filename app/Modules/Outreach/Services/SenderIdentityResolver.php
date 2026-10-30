<?php

declare(strict_types=1);

namespace App\Modules\Outreach\Services;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Onboarding\Models\BusinessProfile;
use App\Modules\Outreach\Data\SenderIdentity;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Builds the {@see SenderIdentity} a send needs, or returns null when the team can't produce one
 * (docs/compliance.md §3.3).
 *
 * Null is a refusal, not a default. Three things have to be in place, and each is missing for a
 * reason the team can act on:
 *
 * 1. **A verified envelope address**, from `config/outreach.php` — the deployment's Resend sender.
 *    Absent on a deployment that hasn't set up sending, which is why sending can be switched off
 *    rather than left to fail per message.
 * 2. **A trading name**, from the team's business profile. The footer has to name the party doing
 *    the processing, and "a team on Alima" is not sender identification. A team that skipped
 *    onboarding is sent back to it.
 * 3. **A real reply-to**, the member's own verified address. A marketing message a recipient
 *    cannot reply to is not one they can opt out of by replying, which is the opt-out route the
 *    footer offers.
 */
final class SenderIdentityResolver
{
    public function resolve(Team $team, User $member): ?SenderIdentity
    {
        $fromAddress = $this->configured('outreach.from.address');

        if ($fromAddress === null) {
            return null;
        }

        $profile = BusinessProfile::query()->where('team_id', $team->getKey())->first();

        // The trading name the recipient will see. The business profile's company name is the one
        // the team authored; the team's own name is a workspace label ("Alex's team") and is not
        // offered as a fallback, because a footer identifying the sender as someone's workspace
        // identifies nobody.
        $senderName = $profile instanceof BusinessProfile
            ? Str::squish((string) $profile->company_name)
            : '';

        if ($senderName === '') {
            return null;
        }

        $replyTo = Str::lower(trim((string) $member->email));

        if ($replyTo === '' || filter_var($replyTo, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        $via = Str::squish((string) config('outreach.from.via', ''));

        return new SenderIdentity(
            fromAddress: $fromAddress,
            // "Acme Ltd via Alima". Both named: the team because they are the controller, the
            // platform because a recipient is entitled to know who transmitted it.
            fromName: Str::limit($via === '' ? $senderName : $senderName.' '.$via, 78, ''),
            replyTo: $replyTo,
            replyToName: Str::limit(Str::squish((string) $member->name), 78, ''),
            senderName: $senderName,
            senderWebsite: $this->website($profile),
            // Falls back to the envelope address, which the platform monitors either way. An
            // opt-out has to reach the global suppression list, so it must not be routed to a
            // tenant mailbox that may never forward it.
            optOutAddress: $this->configured('outreach.opt_out.address') ?? $fromAddress,
            privacyNoticeUrl: $this->configured('outreach.privacy_notice_url') ?? $this->hostedNotice(),
        );
    }

    /**
     * The platform's own hosted privacy notice (Compliance, Phase 15), used when no notice is
     * configured — so a deployment has a working one without setting a variable, and the footer's
     * link is never missing.
     *
     * Checked with `Route::has()` rather than by depending on the Compliance module: Outreach has
     * to stand up on its own, and a route that is not registered simply means no link, which is the
     * Phase 11 behaviour.
     */
    private function hostedNotice(): ?string
    {
        return Route::has('privacy-notice') ? route('privacy-notice') : null;
    }

    /**
     * A non-empty config string, or null. `env()` returning an empty string for an unset key is the
     * case this exists for — an empty From address would otherwise sail through as "configured".
     */
    private function configured(string $key): ?string
    {
        $value = config($key);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function website(?BusinessProfile $profile): ?string
    {
        if (! $profile instanceof BusinessProfile) {
            return null;
        }

        $website = trim((string) $profile->website);

        return $website === '' ? null : $website;
    }
}
