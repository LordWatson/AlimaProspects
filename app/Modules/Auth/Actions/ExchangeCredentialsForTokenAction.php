<?php

declare(strict_types=1);

namespace App\Modules\Auth\Actions;

use App\Models\User;
use App\Modules\Auth\Data\IssuedApiToken;
use App\Modules\Auth\Data\TokenExchangeData;
use App\Modules\Auth\Exceptions\TwoFactorRequiredException;
use App\Modules\Auth\Services\TwoFactorChallenge;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Credentials in, a scoped personal access token out — the whole of mobile sign-in, in one
 * operation (Phase 18).
 *
 * One Action rather than three steps in the controller, for a reason that is not only tidiness:
 * the second factor and the token have to **commit together**. A recovery code is one-time, so
 * spending one and then failing to mint a token would burn a credential the member cannot get
 * back. The transaction here is what makes "both or neither" true.
 *
 * The order of the three checks is the security property:
 *
 *   1. **The password**, first and always, so nothing after it can reveal anything about an
 *      account to someone who does not already hold its password.
 *   2. **The per-account lockout** ({@see TwoFactorChallenge::assertNotLockedOut}), before any
 *      guess is evaluated.
 *   3. **The second factor**, inside the transaction, with the recovery-code spend.
 */
final class ExchangeCredentialsForTokenAction
{
    /**
     * A real bcrypt hash of a value nobody holds, verified against when the email is unknown.
     *
     * Without it, a missing account skips the hash and answers measurably faster than a real one
     * with a wrong password — an enumeration oracle, and a cheap one to close. It equalises the
     * dominant cost (one bcrypt verification); it does not claim to make the handler
     * constant-time, and nothing here depends on that.
     */
    private const string ABSENT_USER_HASH = '$2y$12$Pvl7cwc7amiHU2zgG0zGQOTDXaaFuWnYLOYajvxRfCLvpCcrIeWR.';

    public function __construct(
        private readonly TwoFactorChallenge $challenge,
        private readonly IssueApiTokenAction $issue,
    ) {}

    /**
     * @throws ValidationException bad credentials, or a wrong second factor
     * @throws TwoFactorRequiredException the account needs a second factor and none was sent
     */
    public function handle(TokenExchangeData $data): IssuedApiToken
    {
        $user = User::query()->where('email', $data->email)->first();

        if (! $this->passwordMatches($user, $data->password)) {
            throw ValidationException::withMessages(['email' => [__('auth.failed')]]);
        }

        // The password is right from here on.
        $this->challenge->assertNotLockedOut($user);

        try {
            return DB::transaction(function () use ($user, $data): IssuedApiToken {
                $this->challenge->verify($user, $data->code, $data->recoveryCode);

                return new IssuedApiToken(
                    user: $user,
                    plainTextToken: $this->issue->handle($user, $data->deviceName, $data->abilities),
                );
            });
        } catch (ValidationException $e) {
            // A wrong second factor, counted against the account **outside** the transaction the
            // rollback has just discarded: the default cache store is the database, so a count
            // written inside it would be rolled back too and every failed guess would be free.
            //
            // A `TwoFactorRequiredException` deliberately does not land here — nothing was
            // guessed, so there is nothing to count.
            $this->challenge->recordFailure($user);

            throw $e;
        }
    }

    private function passwordMatches(?User $user, string $password): bool
    {
        if ($user === null) {
            Hash::check($password, self::ABSENT_USER_HASH);

            return false;
        }

        return Hash::check($password, $user->password);
    }
}
