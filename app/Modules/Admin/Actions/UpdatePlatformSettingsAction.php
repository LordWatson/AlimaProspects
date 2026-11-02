<?php

declare(strict_types=1);

namespace App\Modules\Admin\Actions;

use App\Models\User;
use App\Modules\Admin\Services\PlatformSettings;
use Illuminate\Support\Facades\DB;

/**
 * Apply a screenful of platform settings as **one** change (Phase 19b).
 *
 * The Action exists for the transaction. `PlatformSettings::set()` wraps each key on its own, which
 * is correct for a single setting and wrong for a page: a save of four settings was four
 * transactions, so a failure on the third left the first two applied and the operator looking at a
 * form that half-worked. In a file full of kill switches that is the worst available outcome —
 * especially because the half that applied is whichever half happened to be earlier in the config.
 *
 * It is still **one audit entry per key**, because `set()` writes them and the nested transactions
 * join this one as savepoints. A single "settings updated" entry would record that something
 * changed and not what, which is the opposite of what an audit trail is for.
 */
final class UpdatePlatformSettingsAction
{
    public function __construct(private readonly PlatformSettings $settings) {}

    /**
     * @param  array<string, mixed>  $changes
     * @return list<string> the keys that actually changed
     */
    public function handle(array $changes, User $operator): array
    {
        return DB::transaction(function () use ($changes, $operator): array {
            $applied = [];

            foreach ($changes as $key => $value) {
                // `set()` returns early when the value already matches, so this is the list of
                // things that moved rather than the list of fields on the form.
                $before = $this->settings->get($key);

                $this->settings->set($key, $value, $operator);

                if ($this->settings->get($key) !== $before) {
                    $applied[] = $key;
                }
            }

            return $applied;
        });
    }
}
