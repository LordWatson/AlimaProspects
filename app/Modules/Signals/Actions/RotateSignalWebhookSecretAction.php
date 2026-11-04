<?php

declare(strict_types=1);

namespace App\Modules\Signals\Actions;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Signals\Exceptions\SignalsException;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Models\SignalWebhookSecret;
use App\Modules\Signals\Services\SignalSpendGuard;
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
        private readonly SignalSpendGuard $spend,
    ) {}

    /**
     * @return string the plaintext secret, to be shown exactly once
     */
    public function handle(Team $team, User $actor, SignalDefinition $definition): string
    {
        // A credential for a source the team's plan does not include is a credential nothing will
        // ever accept (S16 task 1). Refused here as well as at the endpoint, so the screen says so
        // before somebody wires an integration against it.
        $this->spend->assertWebhookSourceAllowed($team);

        return $this->teamContext->run($team, function () use ($team, $actor, $definition): string {
            // **Re-read inside the context rather than trusting the model handed in** (S13b
            // review). The write below is an `updateOrCreate` on `(team_id, signal_definition_id)`,
            // so a foreign definition would not have *leaked* anything — it would have minted a
            // credential against a pairing that should not exist, correctly scoped and still wrong.
            // The controllers already resolve within the team; this is the second lock, and it is
            // the global scope doing the asking rather than a comparison somebody has to remember.
            $owned = SignalDefinition::query()->whereKey($definition->getKey())->first();

            if (! $owned instanceof SignalDefinition || (int) $owned->team_id !== (int) $team->getKey()) {
                throw SignalsException::definitionNotYours();
            }

            $minted = SignalWebhookSecret::mint();

            return DB::transaction(function () use ($team, $actor, $definition, $minted): string {
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

                // **Inside the transaction** (S13b review, finding 15). It was after it, so a
                // committed rotation whose audit write then failed left a credential replaced with
                // nothing recorded about who replaced it — which is the one question an audit log
                // exists to answer, asked of the one kind of row where it is asked in anger. The
                // audit write touches our own table and calls nothing external, so it belongs in
                // the same commit; `DB::afterCommit()` is for the work that must not.
                //
                // The hint and never the secret. An audit row is read by operators and exported; a
                // credential in one would outlive every control this feature has.
                $this->audit->log('signals.webhook_secret_rotated', $definition, [
                    'signal_definition_id' => (int) $definition->getKey(),
                    'hint' => $minted['hint'],
                ], $actor);

                return $minted['secret'];
            });
        });
    }
}
