<?php

declare(strict_types=1);

namespace App\Modules\Signals\Actions;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Models\SignalWebhookSecret;
use Illuminate\Support\Facades\DB;

/**
 * Mint or replace one signal type's webhook secret (S13b, ADR 0023 §7).
 *
 * **One Action for both**, because creating and rotating are the same operation: there is one live
 * secret per type, so "give me a secret" and "give me a new one" both end with exactly one row. A
 * separate create path would be a second place to get the uniqueness wrong.
 *
 * **It returns the plaintext, and that is the only time anybody sees it.** The row stores the
 * secret encrypted and the UI never reads it back, so a screenshot, a support ticket or a later
 * glance at the screen cannot leak it. A customer who loses it rotates it — which is one request
 * and breaks exactly one integration, by design.
 *
 * Rotation is **audited**, because it is a credential change: it silently breaks a customer's
 * running integration until they redeploy, and "who did that and when" is the first question asked
 * when events stop arriving.
 */
final class RotateSignalWebhookSecretAction
{
    public function __construct(
        private readonly TeamContext $teamContext,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return string the plaintext secret, to be shown exactly once
     */
    public function handle(Team $team, User $actor, SignalDefinition $definition): string
    {
        return $this->teamContext->run($team, function () use ($team, $actor, $definition): string {
            $minted = SignalWebhookSecret::mint();

            $secret = DB::transaction(function () use ($team, $definition, $minted): string {
                // Replaced rather than added to: two valid secrets at once is a grace period nobody
                // asked for and a second thing to find when one leaks. `updateOrCreate` on the
                // unique pair is what makes "mint" and "rotate" the same call.
                SignalWebhookSecret::query()->updateOrCreate(
                    [
                        'team_id' => $team->getKey(),
                        'signal_definition_id' => $definition->getKey(),
                    ],
                    [
                        ...$minted,
                        // A rotated secret has never been used, and saying so is useful: "last used:
                        // never" beside a secret minted an hour ago is how a member learns their
                        // integration is still signing with the old one.
                        'last_used_at' => null,
                    ],
                );

                return $minted['secret'];
            });

            // The hint and never the secret. An audit row is read by operators and exported; a
            // credential in one would outlive every control this feature has.
            $this->audit->log('signals.webhook_secret_rotated', $definition, [
                'signal_definition_id' => (int) $definition->getKey(),
                'hint' => $minted['hint'],
            ], $actor);

            return $secret;
        });
    }
}
