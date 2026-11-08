<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Support;

use App\Modules\Compliance\Models\DataSubjectRequest;
use Illuminate\Support\Facades\URL;

/**
 * The signed verification link in a data-subject request email (PL5).
 *
 * Signed and **temporary**, the same shape `InvitationUrl` uses: the signature is the
 * authentication — there is no account to log in to — and the expiry is what stops a link sitting
 * in an archived mailbox being replayed a year later by somebody who bought the mailbox.
 *
 * It carries the request id and nothing else. The id discloses nothing on its own: the request is
 * not readable without the signature, and the page it lands on says only that the request was
 * confirmed.
 */
final class SubjectRequestUrl
{
    public static function verification(DataSubjectRequest $request): string
    {
        return URL::temporarySignedRoute(
            'privacy.request.verify',
            now()->addDays(max(1, (int) config('compliance.subject_requests.link_days', 14))),
            ['request' => $request->getKey()],
        );
    }
}
