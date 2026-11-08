<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Actions;

use App\Modules\Compliance\Data\SubjectRequestData;
use App\Modules\Compliance\Enums\SubjectRequestStatus;
use App\Modules\Compliance\Mail\VerifySubjectRequestMail;
use App\Modules\Compliance\Models\DataSubjectRequest;
use App\Modules\Compliance\Support\SubjectRequestUrl;
use App\Modules\Core\Support\ContactIdentity;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Records a request from somebody who is not a user, and emails them a link to prove it was them
 * (PL5, `docs/compliance.md` section 3.17).
 *
 * **Nothing happens yet.** The row is written `pending_verification`, no suppression is created, no
 * purge is queued, and it does not appear in an operator's queue. An unverified form is a way to
 * erase a competitor's prospects by typing their address into it, and a queue that showed
 * unverified requests would just move the attack one screen along.
 *
 * **A repeat submission re-sends rather than duplicating, and changes nothing.** Somebody who does
 * not receive the first email submits again, and two rows for one person would be answered twice and
 * counted twice — so the existing pending request for the same address and type is reused. It is
 * returned **untouched**: letting the second submission refresh the details looked like a courtesy
 * and was a way for anybody who knows an address to rewrite a pending request's phone number and
 * free text before the real person clicks their link. The mail goes out **after** commit, because a
 * queued mail carrying an id the transaction then rolled back is a link to nothing, and behind a
 * **per-address cooldown**, because the per-IP throttle does not stop a rotating-IP caller using
 * this endpoint to post a stranger an email from us every few seconds.
 *
 * The log carries the request id and the type and **not the address**, which is the rule for every
 * log line in this module: a log of people who asked us to forget them must not be a copy of them.
 */
final class SubmitSubjectRequestAction
{
    public function handle(SubjectRequestData $data, ?string $ip = null): DataSubjectRequest
    {
        $request = DB::transaction(function () use ($data, $ip): DataSubjectRequest {
            $existing = DataSubjectRequest::query()
                ->where('email', $data->email)
                ->where('type', $data->type->value)
                ->where('status', SubjectRequestStatus::PendingVerification->value)
                ->first();

            if ($existing instanceof DataSubjectRequest) {
                // **Returned untouched.** Overwriting the details looked like a courtesy — their
                // second attempt may be better typed — and is a tamper vector: anybody who knows
                // an address could rewrite the pending request's type, phone and free text before
                // the real person clicks their link. A repeat submission says "send it again" and
                // nothing more.
                return $existing;
            }

            return DataSubjectRequest::query()->create([
                'type' => $data->type->value,
                'status' => SubjectRequestStatus::PendingVerification->value,
                'email' => $data->email,
                'name' => $data->name,
                'company' => $data->company,
                'details' => $data->details,
                'phone' => $data->phone,
                'submitted_ip' => $ip,
            ]);
        });

        // After commit: the link carries the id, and a mail sent inside the transaction could
        // arrive pointing at a row that never existed.
        //
        // **Behind a per-address cooldown**, which is the brake the per-IP throttle cannot be. The
        // throttle stops one visitor flooding us; it does not stop a rotating-IP caller using this
        // endpoint to post a stranger's inbox an email from us every few seconds, and that is the
        // abuse an unauthenticated form that sends mail actually attracts. Keyed on the hashed
        // address because this is a cache, not a record, and `Cache::add` is the atomic
        // "first one through" primitive — a read-then-write would let two concurrent requests both
        // send.
        $sendable = Cache::add(
            'dsar-verify:'.ContactIdentity::hash($request->email),
            true,
            max(1, (int) config('compliance.subject_requests.resend_cooldown', 300)),
        );

        if ($sendable) {
            DB::afterCommit(static function () use ($request): void {
                Mail::to($request->email)->send(new VerifySubjectRequestMail(
                    $request->type,
                    SubjectRequestUrl::verification($request),
                ));
            });
        }

        Log::channel('compliance')->info('Data-subject request submitted.', [
            'request_id' => (int) $request->getKey(),
            'type' => $request->type->value,
        ]);

        return $request;
    }
}
