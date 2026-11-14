<?php

declare(strict_types=1);

namespace App\Modules\AiPlatform\Data;

/**
 * Lets one prompt search the web while it answers (Signals v3 P4, ADR 0039).
 *
 * The provider runs the searches on its own servers and bills each one, on top of the tokens the
 * results take up. So this is never a default: a caller asks for it explicitly, says how many
 * searches the model may run, and gets back the pages those searches returned so it can check
 * the answer against them.
 */
final readonly class AiWebSearch
{
    /**
     * @param  int  $maxSearches  The most searches the model may run in this call.
     * @param  string|null  $country  ISO 3166-1 alpha-2, to localise results ("GB", "AE").
     */
    public function __construct(
        public int $maxSearches = 3,
        public ?string $country = null,
    ) {}
}
