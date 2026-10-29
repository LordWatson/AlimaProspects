<?php

declare(strict_types=1);

use App\Modules\AiPlatform\Services\JsonSchemaValidator;

beforeEach(function (): void {
    $this->validator = new JsonSchemaValidator;
});

it('accepts a valid object and reports missing required properties', function (): void {
    $schema = [
        'type' => 'object',
        'properties' => [
            'summary' => ['type' => 'string'],
            'score' => ['type' => 'integer'],
        ],
        'required' => ['summary', 'score'],
    ];

    expect($this->validator->validate(['summary' => 'ok', 'score' => 10], $schema))->toBe([]);

    $errors = $this->validator->validate(['summary' => 'ok'], $schema);
    expect($errors)->toHaveCount(1)
        ->and($errors[0])->toContain('missing required property [score]');
});

it('reports type mismatches', function (): void {
    $schema = ['type' => 'object', 'properties' => ['score' => ['type' => 'integer']], 'required' => ['score']];

    $errors = $this->validator->validate(['score' => 'not-a-number'], $schema);

    expect($errors)->toHaveCount(1)
        ->and($errors[0])->toContain('expected integer');
});

it('validates array items and item count bounds', function (): void {
    $schema = [
        'type' => 'object',
        'properties' => [
            'tags' => ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'string']],
        ],
        'required' => ['tags'],
    ];

    expect($this->validator->validate(['tags' => ['a', 'b']], $schema))->toBe([]);
    expect($this->validator->validate(['tags' => []], $schema))->not->toBe([]);
    expect($this->validator->validate(['tags' => [1, 2]], $schema))->not->toBe([]);
});

it('enforces enum membership', function (): void {
    $schema = ['type' => 'object', 'properties' => ['grade' => ['type' => 'string', 'enum' => ['A', 'B']]], 'required' => ['grade']];

    expect($this->validator->validate(['grade' => 'A'], $schema))->toBe([]);
    expect($this->validator->validate(['grade' => 'Z'], $schema))->not->toBe([]);
});

it('treats a type union with null as nullable', function (): void {
    $schema = ['type' => 'object', 'properties' => ['website' => ['type' => ['string', 'null']]], 'required' => []];

    expect($this->validator->validate(['website' => null], $schema))->toBe([])
        ->and($this->validator->validate(['website' => 'https://x.test'], $schema))->toBe([])
        ->and($this->validator->validate(['website' => 123], $schema))->not->toBe([]);
});

it('validates nested objects and reports the failing path', function (): void {
    $schema = [
        'type' => 'object',
        'properties' => [
            'variants' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => ['channel' => ['type' => 'string'], 'body' => ['type' => 'string']],
                    'required' => ['channel', 'body'],
                ],
            ],
        ],
        'required' => ['variants'],
    ];

    expect($this->validator->validate(['variants' => [['channel' => 'email', 'body' => 'hi']]], $schema))->toBe([]);

    $errors = $this->validator->validate(['variants' => [['channel' => 'email']]], $schema);
    expect($errors)->not->toBe([])
        ->and($errors[0])->toContain('variants[0]');
});

it('enforces string length bounds', function (): void {
    $schema = ['type' => 'object', 'properties' => ['code' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 4]], 'required' => ['code']];

    expect($this->validator->validate(['code' => 'abc'], $schema))->toBe([])
        ->and($this->validator->validate(['code' => 'a'], $schema))->not->toBe([])
        ->and($this->validator->validate(['code' => 'abcde'], $schema))->not->toBe([]);
});
