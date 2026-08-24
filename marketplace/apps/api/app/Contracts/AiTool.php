<?php

namespace App\Contracts;

use App\Enums\UserRole;
use App\Models\User;

/**
 * A single capability the AI may invoke.
 *
 * The security model of this whole layer rests here. The model never touches an
 * endpoint, a query builder or a model class — it can only name a tool and propose
 * arguments, and a tool is a small, audited, individually authorised function.
 *
 * Three rules every implementation obeys:
 *
 *  1. **The caller is never an argument.** No tool accepts `user_id`, `customer_id`
 *     or `business_id` as a way of saying "act as". The acting user arrives as the
 *     `$user` parameter, taken from the authenticated session by the runner. A model
 *     that hallucinates `{"customer_id": 7}` changes nothing.
 *  2. **Authorisation is the tool's own job**, delegated to the existing policies
 *     wherever one exists. The AI layer adds no second permission system that could
 *     drift from the first.
 *  3. **Write tools return a proposal, never a mutation.** `isWrite() === true`
 *     means the runner parks the call for user confirmation instead of executing it.
 */
interface AiTool
{
    /** Stable snake_case name, as exposed to the model. */
    public function name(): string;

    /**
     * What this does, written for the model.
     *
     * This text is the entire basis on which a tool gets chosen, so it should say
     * when *not* to use it as much as when to.
     */
    public function description(): string;

    /**
     * JSON Schema for the arguments.
     *
     * Doubles as documentation for the model and as the source of the server-side
     * validation rules, so the two cannot disagree.
     *
     * @return array<string, mixed>
     */
    public function parameters(): array;

    /**
     * True if invoking this changes state.
     *
     * Write tools are never executed on the turn they are chosen. They become an
     * `ai_actions` row awaiting explicit user confirmation.
     */
    public function isWrite(): bool;

    /**
     * Roles for which this tool is even visible.
     *
     * Filtering at the registry means an out-of-role tool is not in the model's
     * vocabulary at all — it cannot be called by mistake or by injection, because it
     * was never offered.
     *
     * @return array<int, UserRole>
     */
    public function roles(): array;

    /**
     * Final gate before execution, with the arguments in hand.
     *
     * Role filtering handles "may this kind of user use this tool"; this handles
     * "may *this* user touch *this* record", which is the IDOR question and needs the
     * arguments to answer.
     *
     * @param  array<string, mixed>  $arguments  Already schema-validated.
     */
    public function authorize(User $user, array $arguments): bool;

    /**
     * Run it, and return a compact result for the model.
     *
     * Keep the payload minimal. Anything returned here is sent to the AI provider, so
     * a tool must return the three fields the answer needs rather than a whole record
     * — that is both a privacy control and the single biggest lever on token cost.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function handle(User $user, array $arguments): array;

    /**
     * Human-readable description of what confirming this will do.
     *
     * Generated server-side from the validated arguments, never written by the model
     * — the user must be shown what the backend is actually about to do, not the
     * model's account of it. Read tools may return null.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function confirmationSummary(array $arguments): ?string;
}
