<?php

namespace App\Services\Ai;

use App\Contracts\AiTool;
use App\Enums\AiConversationType;
use App\Models\User;

/**
 * The catalogue of tools, and — more importantly — the filter that decides which of
 * them a given user is even allowed to know about.
 *
 * Filtering at this layer rather than at execution is a deliberate defence. An
 * unavailable tool is never named in the prompt, so the model cannot select it, and a
 * prompt injection cannot talk it into selecting something it was never told exists.
 * Execution-time authorisation still runs, but it is the second lock, not the first.
 */
class ToolRegistry
{
    /** @var array<string, AiTool> */
    private array $tools = [];

    /** @param  iterable<AiTool>  $tools */
    public function __construct(iterable $tools = [])
    {
        foreach ($tools as $tool) {
            $this->register($tool);
        }
    }

    public function register(AiTool $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    public function get(string $name): ?AiTool
    {
        return $this->tools[$name] ?? null;
    }

    /** @return array<string, AiTool> */
    public function all(): array
    {
        return $this->tools;
    }

    /**
     * Tools this user's role permits.
     *
     * @return array<string, AiTool>
     */
    public function availableFor(User $user): array
    {
        return array_filter(
            $this->tools,
            fn (AiTool $tool): bool => in_array($user->role, $tool->roles(), true)
        );
    }

    /**
     * Tools offered in a given kind of thread.
     *
     * Narrower than role alone. A requirement-builder thread has no business
     * listing the user's orders: keeping the vocabulary tight to the task both
     * improves tool selection and shrinks the blast radius if a prompt goes wrong.
     *
     * @return array<string, AiTool>
     */
    public function availableForConversation(User $user, AiConversationType $type): array
    {
        $available = $this->availableFor($user);

        $allowed = match ($type) {
            AiConversationType::RequirementBuilder => [
                'list_categories', 'search_businesses', 'create_requirement_draft', 'update_requirement_draft',
                'get_marketplace_help',
            ],
            AiConversationType::SearchAssistant => [
                'list_categories', 'search_businesses', 'get_business_profile', 'get_marketplace_help',
            ],
            // The three assistants get everything their role allows; that breadth is
            // the point of an assistant.
            default => null,
        };

        if ($allowed === null) {
            return $available;
        }

        return array_filter(
            $available,
            fn (AiTool $tool): bool => in_array($tool->name(), $allowed, true)
        );
    }

    /**
     * Provider-facing tool definitions.
     *
     * @param  array<string, AiTool>  $tools
     * @return array<int, array<string, mixed>>
     */
    public function toSchema(array $tools): array
    {
        return array_values(array_map(fn (AiTool $tool): array => [
            'name' => $tool->name(),
            'description' => $tool->description(),
            'parameters' => $tool->parameters(),
        ], $tools));
    }

    /**
     * A compact catalogue for the system prompt.
     *
     * Needed because tool-calling support is uneven: some local models ignore the
     * `tools` field entirely. Listing the tools in prose lets those models still
     * request one in a structured reply, so the assistant degrades in quality rather
     * than stopping working.
     *
     * @param  array<string, AiTool>  $tools
     */
    public function describeForPrompt(array $tools): string
    {
        if ($tools === []) {
            return 'No tools are available in this conversation.';
        }

        return collect($tools)
            ->map(function (AiTool $tool): string {
                $required = $tool->parameters()['required'] ?? [];
                $arguments = array_keys($tool->parameters()['properties'] ?? []);

                return sprintf(
                    '- %s(%s)%s: %s',
                    $tool->name(),
                    implode(', ', $arguments),
                    $required === [] ? '' : ' [required: '.implode(', ', $required).']',
                    $tool->description(),
                );
            })
            ->implode("\n");
    }
}
