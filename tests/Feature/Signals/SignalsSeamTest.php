<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/**
 * The direction of the dependency between Signals and Billing (`CLAUDE.md` §3).
 *
 * **A test rather than a convention, because the convention was already broken once.** S16 added
 * `SignalQuotaSource` with the usual docblock promising that nothing in this module imports
 * `App\Modules\Billing` — and then imported `Billing\Services\PlanSignalQuotaSource` into
 * `SignalAllowance`, purely for a `{@see}`. It cost nothing at runtime and made three docblocks
 * false, which is the kind of thing a reviewer finds once and a grep finds every time.
 *
 * The rule is not about `use` statements for their own sake. A module that names another's classes
 * is one that cannot be reasoned about, tested or deployed without it, and the four quota seams
 * exist precisely so the owning module never has to. Prose and backticks are how the relationship
 * gets documented instead — which is why this matches an import and not a mention.
 *
 * No `RefreshDatabase`: it reads the source tree and touches nothing else.
 */
it('never imports Billing anywhere in the Signals module', function (): void {
    $offenders = [];

    foreach (File::allFiles(app_path('Modules/Signals')) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        foreach (preg_split('/\R/', (string) File::get($file->getRealPath())) ?: [] as $number => $line) {
            // Only a real import. A class named inside a comment is how the relationship is
            // *supposed* to be documented, so matching those too would forbid the right answer.
            if (preg_match('/^use\s+App\\\\Modules\\\\Billing\\\\/', ltrim($line)) === 1) {
                $offenders[] = $file->getRelativePathname().':'.($number + 1);
            }
        }
    }

    expect($offenders)->toBe([]);
});
