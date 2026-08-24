<?php

namespace App\Support\Spam;

/**
 * Heuristics over user-written free text.
 *
 * ---------------------------------------------------------------------------
 * DESIGN POSITION: DELIBERATELY BLUNT
 * ---------------------------------------------------------------------------
 * These checks catch the obvious cases and nothing else. That is the intended
 * ceiling, not a shortfall.
 *
 * A real requirement on this marketplace can read like this:
 *
 *     "Need 200 M12x40 bolts IS-1367 class 8.8, ref drawing at
 *      https://drive.example/xyz — GST invoice required, ASAP"
 *
 * Short, shouty in places, contains a link and a part number. Any classifier
 * tuned tightly enough to catch clever spam will reject that, and a buyer whose
 * genuine requirement is refused as spam does not try again — they leave. The
 * cost of a false positive here is a lost customer; the cost of a false negative
 * is a moderator spending ten seconds hiding a row.
 *
 * So the asymmetry is priced in on purpose. Subtler judgement belongs in
 * moderation, where a human decides, not in a validator returning 422.
 *
 * Every method is pure and takes its thresholds from config/antispam.php, which
 * keeps the policy reviewable in one place and tunable without a deploy.
 */
class SuspiciousContent
{
    /**
     * @return list<string> Reasons the text looks like spam. Empty means it passed.
     */
    public static function reasons(?string $text): array
    {
        if ($text === null || trim($text) === '') {
            return [];
        }

        $config = (array) config('antispam.content', []);
        $reasons = [];

        if (($config['reject_html'] ?? true) && self::containsMarkup($text)) {
            $reasons[] = 'markup';
        }

        if (self::linkCount($text) > (int) ($config['max_links'] ?? 4)) {
            $reasons[] = 'excessive_links';
        }

        if (self::hasCharacterRun($text, (int) ($config['max_repeated_chars'] ?? 7))) {
            $reasons[] = 'repeated_characters';
        }

        if (self::isShouting(
            $text,
            (float) ($config['max_uppercase_ratio'] ?? 0.6),
            (int) ($config['uppercase_min_length'] ?? 40),
        )) {
            $reasons[] = 'excessive_capitals';
        }

        return $reasons;
    }

    public static function isSuspicious(?string $text): bool
    {
        return self::reasons($text) !== [];
    }

    /**
     * Markup, script and template-injection markers.
     *
     * Rejected rather than stripped. Every field this guards is rendered as plain
     * text, so a tag has no legitimate purpose in one — and a sanitiser that
     * silently rewrites input is both a thing to eventually get around and a
     * thing the user is never told about.
     *
     * `javascript:` and `on…=` are included because they are the payload half of
     * an XSS even when the angle brackets arrive by another route.
     */
    private static function containsMarkup(string $text): bool
    {
        $patterns = [
            '/<\s*(script|iframe|object|embed|style|link|meta|svg|form|input)\b/i',
            '/<\/?\s*[a-z][a-z0-9]*\s*(\s[^>]*)?>/i',   // any HTML-ish tag
            '/javascript\s*:/i',
            '/\bon(?:click|error|load|mouseover|focus|submit)\s*=/i',
            '/\{\{.*?\}\}/s',                            // template interpolation
            '/<\?(?:php|=)/i',                           // PHP open tag
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Count links.
     *
     * Bare domains count too ("visit cheapstuff.biz now"), because requiring a
     * scheme would miss the most common form of comment spam. The TLD is bounded
     * to 2–24 letters so a decimal number or a filename is not read as a domain.
     */
    private static function linkCount(string $text): int
    {
        $withScheme = preg_match_all('~\bhttps?://~i', $text);
        $bare = preg_match_all('~\b(?:www\.[a-z0-9-]+|[a-z0-9-]{2,}\.(?:com|net|org|io|biz|info|ru|cn|xyz|top|shop|online|site))\b~i', $text);

        return (int) $withScheme + (int) $bare;
    }

    /**
     * The same character repeated `$max` or more times in a row.
     *
     * Unicode-aware (`/u`) so this behaves on Devanagari and other scripts rather
     * than matching half a multi-byte character.
     */
    private static function hasCharacterRun(string $text, int $max): bool
    {
        if ($max < 2) {
            return false;
        }

        return preg_match('/(.)\1{'.($max - 1).',}/u', $text) === 1;
    }

    /**
     * Shouting: the share of cased letters that are capitals.
     *
     * Only letters are counted — punctuation and digits are not cased, and
     * including them would let "₹5,00,000 !!!" read as lowercase and dilute the
     * ratio. Short strings are exempt entirely so "ASAP", "GST" and "OEM" cannot
     * trip it.
     */
    private static function isShouting(string $text, float $maxRatio, int $minLength): bool
    {
        if (mb_strlen($text) < $minLength) {
            return false;
        }

        $letters = preg_replace('/[^\p{L}]/u', '', $text) ?? '';
        $count = mb_strlen($letters);

        if ($count === 0) {
            return false;
        }

        $upper = mb_strlen(preg_replace('/[^\p{Lu}]/u', '', $letters) ?? '');

        return ($upper / $count) > $maxRatio;
    }

    /**
     * Whether the text carries enough substance to be worth a supplier's time.
     *
     * Length is measured after collapsing whitespace and stripping repeated
     * punctuation, so "....." and "a a a a a a" do not pass on raw character
     * count alone.
     */
    public static function isTooThin(?string $text, ?int $minLength = null): bool
    {
        $minLength ??= (int) config('antispam.content.min_meaningful_length', 15);

        $normalised = trim(preg_replace('/\s+/u', ' ', (string) $text) ?? '');
        $meaningful = preg_replace('/[^\p{L}\p{N}\s]/u', '', $normalised) ?? '';

        return mb_strlen(trim($meaningful)) < $minLength;
    }
}
