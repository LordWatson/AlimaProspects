<?php

declare(strict_types=1);

namespace App\Modules\Onboarding\Exceptions;

use App\Modules\Core\Exceptions\DomainException;

final class OnboardingException extends DomainException
{
    protected string $errorCode = 'onboarding.invalid';

    /**
     * The step keys that were still missing when completion was attempted.
     *
     * @var list<string>
     */
    public array $missingSteps = [];

    /**
     * Onboarding can't be completed because required steps are still missing.
     *
     * @param  list<string>  $missingSteps
     */
    public static function incomplete(array $missingSteps): self
    {
        $e = new self('Please complete every step before finishing setup.');
        $e->errorCode = 'onboarding.incomplete';
        $e->missingSteps = $missingSteps;

        return $e;
    }
}
