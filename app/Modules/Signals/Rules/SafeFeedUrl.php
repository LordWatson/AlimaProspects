<?php

declare(strict_types=1);

namespace App\Modules\Signals\Rules;

use App\Modules\Core\Support\Security\SafeUrl;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A feed URL this application is allowed to fetch (S13b).
 *
 * **Validation, not just a guard.** `FeedSignalSource` re-checks the address immediately before
 * every request — it has to, because `SafeUrl` resolves DNS and a host that was public when
 * somebody pasted it can point at `127.0.0.1` by the time a worker fetches it. But a member typing
 * a URL into a form deserves "we can't reach that address" beside the field rather than a silent
 * source that finds nothing and a warning in a log they will never read.
 *
 * So this is the same rule asked at the earliest useful moment. It is not a substitute for the
 * runtime check and does not try to be: rebinding is exactly the attack of passing this and then
 * changing the answer.
 */
final class SafeFeedUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value) || ! SafeUrl::allows($value)) {
            // Deliberately vague about *why*. "That resolves to a private address" tells somebody
            // probing our network which addresses are internal, and a member who pasted a typo is
            // no better served by the detail.
            $fail('Enter a web address Alima can reach, starting with https://.');
        }
    }
}
