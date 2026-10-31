<?php

declare(strict_types=1);

namespace App\Modules\Core\Support\ApiDocs;

use App\Modules\Core\Enums\ApiErrorCode;
use App\Modules\Core\Http\Responses\ApiError;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Server;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Symfony\Component\HttpFoundation\Response as Status;

/**
 * Writes the parts of the `/api/v1` contract that no controller signature can express into the
 * generated OpenAPI document (Phase 17).
 *
 * Scramble documents what each endpoint *returns*. Two things it cannot see:
 *
 * - **The error envelope.** What every endpoint returns when it fails lives in one middleware and
 *   one renderer, not in any return type, so nothing infers it. A spec that describes only the
 *   happy path produces a generated client that treats every failure as a parse error.
 * - **A stable server URL.** Scramble resolves the server through `url()`, which bakes the
 *   generating machine's `APP_URL` into the document. The specification is committed, so that
 *   would make it differ per developer and turn every regeneration into a spurious diff. A
 *   relative server is both honest and the same everywhere.
 *
 * Registered from `CoreServiceProvider` behind a `class_exists()` check, because Scramble is a
 * build-time dev dependency and must not be reachable from a production boot.
 *
 * @see ApiError
 */
final class DocumentsApiContract
{
    /**
     * The statuses every authenticated endpoint can answer with, whatever it does.
     */
    private const array UNIVERSAL = [
        Status::HTTP_UNAUTHORIZED => 'No credential, or one that has expired.',
        Status::HTTP_FORBIDDEN => 'The member may not do this, or the token is not scoped for it (`insufficient_token_ability`).',
        Status::HTTP_TOO_MANY_REQUESTS => 'Rate limited. `Retry-After` says how long to wait.',
    ];

    public function __invoke(OpenApi $openApi): void
    {
        $openApi->servers = [];
        $openApi->addServer(Server::make('/api/v1')->setDescription('This deployment'));

        $envelope = $openApi->components->addSchema('ApiError', Schema::fromType($this->envelope()));

        foreach ($openApi->paths as $path) {
            $hasParameters = str_contains($path->path, '{');

            foreach ($path->operations as $operation) {
                $this->document($operation, $envelope, $hasParameters);
            }
        }
    }

    private function document(Operation $operation, Reference $envelope, bool $hasParameters): void
    {
        $documented = array_map(
            static fn (Response|Reference $response): int|string|null => $response instanceof Response
                ? $response->code
                : null,
            $operation->responses,
        );

        $statuses = self::UNIVERSAL;

        // A 404 only means something where there is a path parameter that could point at a record
        // this team cannot see — which, for a tenant-scoped API, is most of them.
        if ($hasParameters) {
            $statuses[Status::HTTP_NOT_FOUND] = 'No such record, or none this team can see.';
        }

        // A validation failure is only reachable where there is something to validate.
        if (! in_array($operation->method, ['get', 'head', 'delete'], true)) {
            $statuses[Status::HTTP_UNPROCESSABLE_ENTITY] = 'Validation failed. `error.details` is keyed by input name.';
        }

        ksort($statuses);

        foreach ($statuses as $status => $description) {
            if (in_array($status, $documented, true)) {
                continue;
            }

            $operation->addResponse(
                Response::make($status)
                    ->setDescription($description)
                    ->setContent('application/json', Schema::fromType($envelope)),
            );
        }
    }

    /**
     * `{ "error": { "code": …, "message": …, "details"?: … } }`.
     */
    private function envelope(): ObjectType
    {
        $codes = implode(', ', array_map(
            static fn (ApiErrorCode $code): string => '`'.$code->value.'`',
            ApiErrorCode::cases(),
        ));

        $error = (new ObjectType)
            ->addProperty('code', (new StringType)->setDescription(
                'Stable, machine-readable. Framework-level codes are one of '.$codes
                .'; a module raises its own, e.g. `outreach.suppressed`. Branch on this, never on the message.',
            ))
            ->addProperty('message', (new StringType)->setDescription(
                'Prose for a person. Translated, and free to change inside a version.',
            ))
            ->addProperty('details', (new ObjectType)->setDescription(
                'Present only when there is something structured to say — field errors keyed by input '
                .'name on a 422, `required_abilities` on a token scope failure.',
            ))
            ->setRequired(['code', 'message']);

        return (new ObjectType)
            ->addProperty('error', $error)
            ->setRequired(['error']);
    }
}
