<?php

namespace App\Services\Ai;

use App\Contracts\AiTool;
use App\Models\AiAction;
use App\Models\AiConversation;
use App\Models\User;
use App\Services\Ai\Data\AiToolCall;
use App\Services\Ai\Data\ToolResult;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Executes a tool call the model proposed — after taking it apart and checking every
 * piece.
 *
 * This class is where "the AI must not bypass authorization" is actually enforced.
 * The sequence is fixed and there is no path around it:
 *
 *   resolve the name against the registry  (unknown tool → refused)
 *   check the role                         (out-of-role → refused)
 *   strip unknown arguments                (injected extras dropped)
 *   validate against the tool's schema     (bad types → refused)
 *   ask the tool to authorise the record   (IDOR → refused)
 *   if it writes → park for confirmation   (never executes here)
 *   otherwise execute, and audit it
 *
 * Nothing in that list is skippable by prompt wording, because none of it is decided
 * by the prompt.
 */
class ToolRunner
{
    public function __construct(
        private readonly ToolRegistry $registry,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, AiTool>  $availableTools  Already narrowed for this thread.
     */
    public function run(
        AiToolCall $call,
        User $user,
        array $availableTools,
        ?AiConversation $conversation = null,
    ): ToolResult {
        // A model naming a tool it was not offered is either confused or being
        // steered. Either way the answer is the same, and the attempt is worth
        // recording.
        $tool = $availableTools[$call->name] ?? null;

        if ($tool === null) {
            $this->audit->log('ai.tool.rejected', $conversation, $user, [
                'tool' => $call->name,
                'reason' => 'not_available',
            ]);

            return ToolResult::failure($call->name, sprintf(
                'Tool "%s" is not available to you. Only use the tools listed for this conversation.',
                $call->name
            ));
        }

        // Second lock, in case a caller passed an unfiltered tool list.
        if (! in_array($user->role, $tool->roles(), true)) {
            $this->audit->log('ai.tool.rejected', $conversation, $user, [
                'tool' => $tool->name(),
                'reason' => 'role_denied',
            ]);

            return ToolResult::failure($tool->name(), 'You do not have permission to use this tool.');
        }

        try {
            $arguments = $this->validateArguments($tool, $call->arguments);
        } catch (ValidationException $exception) {
            // Handed back to the model as a correctable error rather than thrown, so
            // it can retry with a fixed argument instead of the turn dying.
            return ToolResult::failure($tool->name(), 'Invalid arguments: '.collect($exception->errors())
                ->flatten()
                ->implode(' '));
        }

        // The record-level gate. Role said "this kind of user may"; this says "this
        // user may touch this row".
        if (! $tool->authorize($user, $arguments)) {
            $this->audit->log('ai.tool.rejected', $conversation, $user, [
                'tool' => $tool->name(),
                'reason' => 'unauthorized_resource',
            ]);

            return ToolResult::failure($tool->name(), 'You do not have access to that resource.');
        }

        if ($tool->isWrite()) {
            return $this->propose($tool, $user, $arguments, $conversation);
        }

        try {
            $data = $tool->handle($user, $arguments);
        } catch (Throwable $exception) {
            report($exception);

            return ToolResult::failure($tool->name(), 'That lookup failed. Please try again.');
        }

        // Reads are audited at debug significance only when they touch a named
        // record; a category list is not an event worth a row. Writes are always
        // audited, in confirm().
        return ToolResult::success($tool->name(), $data);
    }

    /**
     * Park a write tool for confirmation.
     *
     * The user sees a summary composed by the tool from validated arguments — never
     * the model's description of what it is about to do. If the model says "I'll
     * create a draft for 500 chairs" but the arguments say 50, the confirmation
     * prompt shows 50, because that is what will actually happen.
     */
    private function propose(AiTool $tool, User $user, array $arguments, ?AiConversation $conversation): ToolResult
    {
        $summary = $tool->confirmationSummary($arguments) ?? sprintf('Run %s', $tool->name());

        $action = new AiAction([
            'ai_conversation_id' => $conversation?->id,
            'tool' => $tool->name(),
            'arguments' => $arguments,
            'summary' => $summary,
        ]);
        $action->user_id = $user->id;
        $action->save();

        $this->audit->log('ai.action.proposed', $action, $user, [
            'tool' => $tool->name(),
            'summary' => $summary,
        ]);

        return ToolResult::awaitingConfirmation($tool->name(), $action->uuid, $summary);
    }

    /**
     * Execute a previously proposed action, now that the user has confirmed it.
     *
     * Everything is re-checked. The arguments are re-validated and the tool
     * re-authorises, because minutes have passed since the proposal and the world may
     * have moved: a quote can expire, an order can be cancelled, a business can be
     * suspended. Trusting the earlier check would create a window where a stale
     * approval executes against a changed database.
     *
     * @throws ValidationException
     */
    public function executeConfirmed(AiAction $action, User $user): ToolResult
    {
        if ($action->user_id !== $user->id) {
            // Should be unreachable — the controller scopes by owner — but a
            // confirmation endpoint is exactly where an IDOR would be attempted.
            return ToolResult::failure($action->tool, 'You cannot confirm this action.');
        }

        if ($action->hasExpired()) {
            $action->markExpired();

            return ToolResult::failure($action->tool, 'This action expired. Please ask again.');
        }

        if (! $action->isConfirmable()) {
            return ToolResult::failure($action->tool, sprintf(
                'This action is already %s.',
                strtolower($action->status->label())
            ));
        }

        $tool = $this->registry->get($action->tool);

        if ($tool === null || ! in_array($user->role, $tool->roles(), true)) {
            $action->markFailed('Tool no longer available.');

            return ToolResult::failure($action->tool, 'That action is no longer available.');
        }

        try {
            $arguments = $this->validateArguments($tool, (array) $action->arguments);
        } catch (ValidationException $exception) {
            $action->markFailed('Arguments are no longer valid.');

            throw $exception;
        }

        if (! $tool->authorize($user, $arguments)) {
            $action->markFailed('Not authorised.');

            return ToolResult::failure($action->tool, 'You no longer have access to that resource.');
        }

        $action->markConfirmed();

        try {
            $data = $tool->handle($user, $arguments);
        } catch (ValidationException $exception) {
            // Business-rule refusals are the interesting case: "you cannot publish a
            // requirement without a location" must reach the user verbatim, because
            // it tells them exactly what to fix.
            $action->markFailed(collect($exception->errors())->flatten()->implode(' '));

            $this->audit->log('ai.action.failed', $action, $user, ['tool' => $tool->name()]);

            return ToolResult::failure($action->tool, collect($exception->errors())->flatten()->implode(' '));
        } catch (Throwable $exception) {
            report($exception);
            $action->markFailed('Execution failed.');

            $this->audit->log('ai.action.failed', $action, $user, ['tool' => $tool->name()]);

            return ToolResult::failure($action->tool, 'That action could not be completed.');
        }

        $action->markExecuted($data);

        // A write performed on a user's behalf is exactly the kind of event the
        // Phase 10 audit trail exists for.
        $this->audit->log('ai.action.executed', $action, $user, [
            'tool' => $tool->name(),
            'summary' => (string) $action->summary,
        ]);

        return ToolResult::success($tool->name(), $data);
    }

    public function reject(AiAction $action, User $user): void
    {
        $action->markRejected();

        $this->audit->log('ai.action.rejected', $action, $user, ['tool' => $action->tool]);
    }

    /**
     * Validate arguments against the tool's own JSON Schema.
     *
     * Unknown keys are dropped rather than rejected. A model inventing
     * `{"customer_id": 3}` alongside valid arguments is common, and the safe response
     * is to ignore the extra field — the acting user comes from the session, so the
     * invented one was never going to be honoured anyway. Rejecting outright would
     * turn a harmless model quirk into a failed turn.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validateArguments(AiTool $tool, array $arguments): array
    {
        $schema = $tool->parameters();
        $properties = (array) ($schema['properties'] ?? []);
        $required = (array) ($schema['required'] ?? []);

        $known = array_intersect_key($arguments, $properties);
        $rules = [];

        foreach ($properties as $key => $definition) {
            $rule = [in_array($key, $required, true) ? 'required' : 'nullable'];

            $rule[] = match ($definition['type'] ?? 'string') {
                'integer' => 'integer',
                'number' => 'numeric',
                'boolean' => 'boolean',
                'array' => 'array',
                'object' => 'array',
                default => 'string',
            };

            if (isset($definition['enum']) && is_array($definition['enum'])) {
                $rule[] = 'in:'.implode(',', $definition['enum']);
            }

            if (isset($definition['minimum'])) {
                $rule[] = 'min:'.$definition['minimum'];
            }

            if (isset($definition['maximum'])) {
                $rule[] = 'max:'.$definition['maximum'];
            }

            if (($definition['type'] ?? null) === 'string' && isset($definition['maxLength'])) {
                $rule[] = 'max:'.$definition['maxLength'];
            }

            $rules[$key] = $rule;
        }

        return Validator::make($known, $rules)->validate();
    }
}
