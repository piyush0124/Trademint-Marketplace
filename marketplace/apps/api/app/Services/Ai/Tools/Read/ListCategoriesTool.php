<?php

namespace App\Services\Ai\Tools\Read;

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\User;
use App\Services\Ai\Tools\Concerns\BaseTool;

/**
 * The marketplace's real category tree.
 *
 * This is the tool that stops the AI inventing categories. When the model needs to
 * classify a requirement it must first see what actually exists, and every category
 * it later names is checked back against this table — so "Office Interior Design"
 * can only be proposed if a row by that name is really there.
 *
 * Public data, so every role may read it.
 */
class ListCategoriesTool extends BaseTool
{
    public function name(): string
    {
        return 'list_categories';
    }

    public function description(): string
    {
        return 'List the marketplace categories that actually exist, optionally filtered by a search term. '
            .'Use this before suggesting any category, and only ever suggest a category returned by this tool.';
    }

    public function parameters(): array
    {
        return $this->objectSchema([
            'search' => $this->stringProperty('Optional keyword to narrow the list, e.g. "interior" or "logistics".'),
            'industries_only' => [
                'type' => 'boolean',
                'description' => 'True to return only top-level industries, false to include specialities.',
            ],
            'limit' => $this->integerProperty('Maximum categories to return (default 25).', 1, 60),
        ]);
    }

    public function roles(): array
    {
        return [UserRole::Customer, UserRole::Owner, UserRole::Admin];
    }

    public function handle(User $user, array $arguments): array
    {
        $query = Category::query()->where('is_active', true);

        if (! empty($arguments['industries_only'])) {
            $query->whereNull('parent_id');
        }

        if (! empty($arguments['search'])) {
            $search = trim((string) $arguments['search']);
            $query->where(fn ($q) => $q->where('name', 'like', "%$search%")->orWhere('slug', 'like', "%$search%"));
        }

        $categories = $query
            ->with('parent:id,name')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->limit($this->limit($arguments, 25, 60))
            ->get();

        return [
            'count' => $categories->count(),
            // Three fields per row, not the whole model. Everything returned here is
            // sent to the provider, so the payload is kept to what the answer needs.
            'categories' => $categories->map(fn (Category $category): array => array_filter([
                'slug' => $category->slug,
                'name' => $category->name,
                'industry' => $category->parent?->name,
            ], fn ($value) => $value !== null))->all(),
            'note' => 'Only these categories exist. Never invent one.',
        ];
    }
}
