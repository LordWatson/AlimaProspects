<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Core\Enums\ApiErrorCode;
use App\Modules\Core\Http\Middleware\ApiVersion;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;

/**
 * Assertions for the `/api/v1` contract (Phase 17), registered as `TestResponse` macros from
 * `tests/Pest.php`.
 *
 * They exist so a test says *what it is asserting* — "this is a validation failure the client can
 * read" — rather than spelling out the envelope's key paths in every file. When the envelope is
 * asserted literally in a hundred places, it stops being one contract and becomes a hundred
 * copies of one, and the next change to it is a day of sed.
 *
 * `assertJsonValidationErrors()` deliberately does **not** work on `/api/v1`: Laravel's native
 * `{message, errors}` 422 is what the web forms consume, and the API answers with the shared
 * envelope instead. Use `assertApiValidationErrors()` there.
 */
final class ApiAssertions
{
    public static function register(): void
    {
        TestResponse::macro(
            'assertApiError',
            /**
             * @param  list<string>|null  $detailKeys
             */
            function (ApiErrorCode|string $code, ?int $status = null, ?array $detailKeys = null): TestResponse {
                /** @var TestResponse $this */
                if ($status !== null) {
                    $this->assertStatus($status);
                }

                $expected = $code instanceof ApiErrorCode ? $code->value : $code;

                $this->assertJsonPath('error.code', $expected);

                $message = $this->json('error.message');

                Assert::assertIsString($message, 'The error envelope must carry a message.');
                Assert::assertNotSame('', $message, 'The error envelope must carry a message.');

                // The envelope replaces the framework's shapes rather than sitting beside them.
                $this->assertJsonMissingPath('message');
                $this->assertJsonMissingPath('errors');

                foreach ($detailKeys ?? [] as $key) {
                    Assert::assertArrayHasKey(
                        $key,
                        (array) $this->json('error.details'),
                        "The error envelope is missing detail for [{$key}].",
                    );
                }

                return $this;
            },
        );

        TestResponse::macro(
            'assertApiValidationErrors',
            /**
             * @param  list<string>|string  $keys  Input names that must appear in `error.details`.
             */
            function (array|string $keys): TestResponse {
                /** @var TestResponse $this */
                return $this->assertApiError(ApiErrorCode::ValidationFailed, 422, (array) $keys);
            },
        );

        TestResponse::macro('assertApiVersion', function (): TestResponse {
            /** @var TestResponse $this */
            $this->assertHeader(ApiVersion::HEADER, ApiVersion::VERSION);

            return $this;
        });
    }
}
