<?php

namespace App\Services\Seo;

/**
 * Everything a crawler should see for one URL.
 *
 * A value object rather than a loose array so the injector cannot silently omit a
 * field — a missing canonical or a missing og:title is the kind of defect that
 * produces no error and costs traffic indefinitely.
 */
final class SeoPayload
{
    /**
     * @param  array<int, array<string, mixed>>  $jsonLd  One or more schema.org graphs.
     */
    public function __construct(
        public readonly string $title,
        public readonly string $description,
        public readonly string $canonical,
        public readonly ?string $image = null,
        public readonly string $type = 'website',
        /**
         * Private and utility pages set this. It is the one field that must never be
         * wrong in the permissive direction: a dashboard that forgets `noindex` gets
         * a customer's order history into a search index, and removal is slow.
         */
        public readonly bool $noIndex = false,
        public readonly array $jsonLd = [],
    ) {}

    /** Truncate at a word boundary for a meta description. */
    public static function summarise(?string $text, int $limit = 155): string
    {
        $clean = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $text)) ?? '');

        if ($clean === '') {
            return '';
        }

        if (mb_strlen($clean) <= $limit) {
            return $clean;
        }

        $cut = mb_substr($clean, 0, $limit);
        $lastSpace = mb_strrpos($cut, ' ');

        // Cutting mid-word looks like a rendering fault in a search result, which is
        // the one place the text is guaranteed to be read.
        return rtrim($lastSpace !== false ? mb_substr($cut, 0, $lastSpace) : $cut, " ,.;:-").'…';
    }
}
