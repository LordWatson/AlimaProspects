<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Data;

use Spatie\LaravelData\Data;

/**
 * What the push provider made of a send (Phase 12).
 *
 * `invalidTokens` is the field that earns this type: a token the provider has permanently rejected
 * must be **deleted**, not retried, or every future notification to that member silently goes
 * nowhere. Everything else here is for the log.
 */
final class PushResult extends Data
{
    /**
     * @param  list<string>  $invalidTokens  Tokens the provider will never accept again — a wiped
     *                                       device, an uninstalled app, a token issued by another
     *                                       project.
     */
    public function __construct(
        public readonly int $sent = 0,
        public readonly int $failed = 0,
        public readonly array $invalidTokens = [],
    ) {}

    public static function none(): self
    {
        return new self;
    }

    public function anySent(): bool
    {
        return $this->sent > 0;
    }
}
