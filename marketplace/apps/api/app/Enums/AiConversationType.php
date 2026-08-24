<?php

namespace App\Enums;

/**
 * The kinds of AI thread the marketplace supports.
 *
 * Type is not cosmetic: it decides which system prompt is used, which tools are
 * even visible to the model, and which role may open the thread at all. That makes
 * data isolation a property of the conversation type rather than something the
 * prompt has to remember to ask for.
 */
enum AiConversationType: string
{
    case CustomerAssistant = 'customer_assistant';
    case BusinessAssistant = 'business_assistant';
    case AdminCopilot = 'admin_copilot';
    case RequirementBuilder = 'requirement_builder';
    case SearchAssistant = 'search_assistant';

    public function label(): string
    {
        return match ($this) {
            self::CustomerAssistant => 'Buyer assistant',
            self::BusinessAssistant => 'Business assistant',
            self::AdminCopilot => 'Admin copilot',
            self::RequirementBuilder => 'Requirement builder',
            self::SearchAssistant => 'Search assistant',
        };
    }

    /**
     * Roles permitted to open this kind of thread.
     *
     * A customer cannot open an admin copilot, and no amount of prompting changes
     * that — it is checked when the conversation is created and again on every turn.
     *
     * @return array<int, UserRole>
     */
    public function allowedRoles(): array
    {
        return match ($this) {
            self::CustomerAssistant, self::RequirementBuilder => [UserRole::Customer],
            self::BusinessAssistant => [UserRole::Owner],
            self::AdminCopilot => [UserRole::Admin],
            // Search help leaks nothing private, so anyone signed in may use it.
            self::SearchAssistant => [UserRole::Customer, UserRole::Owner, UserRole::Admin],
        };
    }

    public function permits(UserRole $role): bool
    {
        return in_array($role, $this->allowedRoles(), true);
    }

    /** The feature flag this thread type is gated behind. */
    public function feature(): string
    {
        return match ($this) {
            self::CustomerAssistant, self::BusinessAssistant => 'assistant',
            self::AdminCopilot => 'admin_copilot',
            self::RequirementBuilder => 'requirement_builder',
            self::SearchAssistant => 'search',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** The natural thread type for a role, used when the client doesn't specify. */
    public static function defaultFor(UserRole $role): self
    {
        return match ($role) {
            UserRole::Customer => self::CustomerAssistant,
            UserRole::Owner => self::BusinessAssistant,
            UserRole::Admin => self::AdminCopilot,
        };
    }
}
