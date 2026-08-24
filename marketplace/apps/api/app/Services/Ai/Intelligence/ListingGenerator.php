<?php

namespace App\Services\Ai\Intelligence;

use App\Exceptions\Ai\AiException;
use App\Exceptions\Ai\AiInvalidResponseException;
use App\Models\Business;
use App\Models\User;
use App\Services\Ai\AiService;
use App\Services\Ai\Extraction\CategoryResolver;
use Illuminate\Support\Str;

/**
 * Phase 13 — drafts listing copy for a business owner to review.
 *
 * Generates and returns; it does not save and it cannot publish. That is not a
 * limitation to be lifted later — a listing is a commercial promise to the market, and
 * a business must own every word of it. The generated draft goes back as JSON, the owner
 * edits it in the normal listing form, and the normal draft → pending_review → approved
 * flow applies unchanged, including admin moderation.
 *
 * Category is resolved against the real taxonomy, so a generated listing can never
 * introduce a category the marketplace does not have.
 */
class ListingGenerator
{
    /** Bounds that keep generated copy usable in the existing UI and columns. */
    private const MAX_TITLE = 120;

    private const MAX_DESCRIPTION = 4000;

    public function __construct(
        private readonly AiService $ai,
        private readonly CategoryResolver $categories,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws AiException
     */
    public function generate(Business $business, string $brief, ?User $user = null): array
    {
        $schema = [
            'title' => 'string — a clear, specific listing title under 90 characters',
            'category' => 'string — one category slug from the list, or null',
            'summary' => 'string — one sentence a buyer sees in search results',
            'description' => 'string — 2 to 4 short paragraphs describing the offering',
            'features' => 'array of 4 to 7 short strings — concrete features or inclusions',
            'service_details' => 'array of short strings — how the work is delivered, e.g. coverage, turnaround, team',
            'faqs' => 'array of objects with question and answer — 3 to 5 genuine buyer questions',
            'seo_description' => 'string — under 160 characters, plain and factual',
            'suggested_unit' => 'string — unit this is priced by, e.g. per unit, per sq ft, per month, per visit, or null',
            'missing_information' => 'array of short strings — what the owner should add that the brief did not say',
        ];

        $raw = $this->ai->extract(
            input: $brief,
            schema: $schema,
            user: $user,
            feature: 'listing_generator',
            instructions: <<<PROMPT
            You are drafting a marketplace listing for an Indian business. Write in plain
            professional English, for a business buyer.

            Rules:
            - Use only what the brief states. Do not invent certifications, awards, client
              names, years of experience, team size, guarantees or prices.
            - No prices anywhere. The owner sets pricing separately.
            - No superlatives ("best in India", "unbeatable") and no invented claims. A buyer
              can check these, and an inflated listing costs the business its credibility.
            - If the brief is thin, write less and list what is missing in missing_information
              rather than padding it out.

            CATEGORY: choose a slug from this list, or null if none fits. Never invent one.
            {$this->categories->promptCatalogue(40)}
            PROMPT,
        );

        if (! is_array($raw) || empty($raw['title'])) {
            // A draft the owner cannot use is worse than an honest failure, because they
            // would have to check every field to discover it was empty.
            throw new AiInvalidResponseException('The assistant could not produce a usable listing draft.');
        }

        $category = $this->categories->resolve(
            is_string($raw['category'] ?? null) ? $raw['category'] : null,
            $brief,
        );

        return [
            'draft' => [
                'title' => $this->text($raw['title'] ?? null, self::MAX_TITLE),
                'category' => $category['category']?->slug,
                'category_name' => $category['category']?->name,
                'summary' => $this->text($raw['summary'] ?? null, 200),
                'description' => $this->text($raw['description'] ?? null, self::MAX_DESCRIPTION),
                'features' => $this->list($raw['features'] ?? null, 8, 120),
                'service_details' => $this->list($raw['service_details'] ?? null, 8, 160),
                'faqs' => $this->faqs($raw['faqs'] ?? null),
                'seo_description' => $this->text($raw['seo_description'] ?? null, 160),
                'suggested_unit' => $this->text($raw['suggested_unit'] ?? null, 30),
            ],
            'meta' => [
                'business' => $business->name,
                'category_confidence' => $category['confidence'],
                'category_options' => collect($category['candidates'])
                    ->map(fn ($option): array => ['slug' => $option->slug, 'name' => $option->name])
                    ->all(),
                // Handed back as a checklist, which is more useful than silently
                // omitting fields the owner would not know were missing.
                'missing_information' => $this->list($raw['missing_information'] ?? null, 6, 160),
                'requires_review' => true,
                'note' => 'Nothing has been saved or published. Review and edit this, then create the listing yourself. '
                    .'It still goes through the normal review process.',
            ],
        ];
    }

    private function text(mixed $value, int $limit): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Str::limit(trim($value), $limit, '');
    }

    /**
     * @return array<int, string>
     */
    private function list(mixed $value, int $max, int $itemLimit): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->filter(fn ($item): bool => is_scalar($item) && trim((string) $item) !== '')
            ->map(fn ($item): string => Str::limit(trim((string) $item), $itemLimit, ''))
            ->unique()
            ->take($max)
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{question: string, answer: string}>
     */
    private function faqs(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->filter(fn ($item): bool => is_array($item) && ! empty($item['question']) && ! empty($item['answer']))
            ->map(fn (array $item): array => [
                'question' => Str::limit(trim((string) $item['question']), 200, ''),
                'answer' => Str::limit(trim((string) $item['answer']), 600, ''),
            ])
            ->take(5)
            ->values()
            ->all();
    }
}
