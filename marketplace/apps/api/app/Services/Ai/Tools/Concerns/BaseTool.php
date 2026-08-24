<?php

namespace App\Services\Ai\Tools\Concerns;

use App\Contracts\AiTool;
use App\Models\User;

/**
 * Shared defaults for tools.
 *
 * The defaults are chosen so that forgetting to override something fails safe: a
 * tool is read-only unless it says otherwise, and `authorize()` returns true only
 * because the registry has already filtered by role — any tool touching a specific
 * record must still override it.
 */
abstract class BaseTool implements AiTool
{
    public function isWrite(): bool
    {
        return false;
    }

    public function authorize(User $user, array $arguments): bool
    {
        return true;
    }

    public function confirmationSummary(array $arguments): ?string
    {
        return null;
    }

    /**
     * Build an object schema without repeating the boilerplate.
     *
     * `additionalProperties: false` is deliberate: it tells the model not to invent
     * fields, and mirrors the runner's behaviour of dropping unknown keys.
     *
     * @param  array<string, mixed>  $properties
     * @param  array<int, string>  $required
     * @return array<string, mixed>
     */
    protected function objectSchema(array $properties = [], array $required = []): array
    {
        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_values($required),
            'additionalProperties' => false,
        ];
    }

    /** @return array<string, mixed> */
    protected function stringProperty(string $description, ?array $enum = null): array
    {
        return array_filter([
            'type' => 'string',
            'description' => $description,
            'enum' => $enum,
        ], fn ($value) => $value !== null);
    }

    /** @return array<string, mixed> */
    protected function integerProperty(string $description, ?int $min = null, ?int $max = null): array
    {
        return array_filter([
            'type' => 'integer',
            'description' => $description,
            'minimum' => $min,
            'maximum' => $max,
        ], fn ($value) => $value !== null);
    }

    /**
     * Clamp a caller-supplied page size.
     *
     * Every list tool needs this. A model asking for 500 rows would blow the context
     * window and the token budget in one go, so the ceiling is enforced here rather
     * than trusted to the schema's `maximum`.
     */
    protected function limit(array $arguments, int $default = 5, int $max = 20): int
    {
        $requested = (int) ($arguments['limit'] ?? $default);

        return max(1, min($max, $requested));
    }
}
