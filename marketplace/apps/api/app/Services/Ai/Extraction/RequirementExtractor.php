<?php

namespace App\Services\Ai\Extraction;

use App\Exceptions\Ai\AiException;
use App\Exceptions\Ai\AiInvalidResponseException;
use App\Models\User;
use App\Services\Ai\AiService;
use App\Services\Ai\PromptLibrary;
use Illuminate\Support\Str;

/**
 * Phase 12 — turns a plain description of a business need into a validated draft
 * proposal.
 *
 * The shape of this class is the whole point. The AI does exactly one job: read prose
 * and guess at fields. Every guess is then re-derived or checked against something
 * authoritative:
 *
 *   category  → CategoryResolver, against the categories table. Cannot invent one.
 *   location  → LocationResolver, against the locations table. Never inserts.
 *   money     → AmountNormaliser, deterministic PHP. The model's number is discarded
 *               if our own parse of the raw text disagrees.
 *   dates     → TimelineNormaliser, deterministic PHP.
 *   quantity  → numeric coercion with a sanity ceiling.
 *
 * What comes out is a *proposal* with per-field confidence and a list of what is still
 * missing — never a saved record. Writing it is a separate, confirmed action through
 * CreateRequirementDraftTool.
 */
class RequirementExtractor
{
    /**
     * Fields a requirement needs before it can be published.
     *
     * Mirrors RequirementService::publish so the assistant asks for exactly what the
     * backend will later insist on, rather than a guess at it.
     */
    private const PUBLISH_REQUIRED = ['title', 'category', 'quantity', 'city', 'required_date'];

    public function __construct(
        private readonly AiService $ai,
        private readonly PromptLibrary $prompts,
        private readonly CategoryResolver $categories,
        private readonly LocationResolver $locations,
        private readonly AmountNormaliser $amounts,
        private readonly TimelineNormaliser $timeline,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws AiException
     */
    public function extract(string $input, ?User $user = null): array
    {
        $raw = $this->askModel($input, $user);

        // Everything below re-derives from `$input` and the database. The model's
        // output is treated as a hint about *where to look*, not as data.
        $category = $this->categories->resolve(
            is_string($raw['category'] ?? null) ? $raw['category'] : null,
            $input,
        );

        $location = $this->locations->resolve(
            is_string($raw['city'] ?? null) ? $raw['city'] : null,
            is_string($raw['state'] ?? null) ? $raw['state'] : null,
        );

        $budget = $this->resolveBudget($raw, $input);
        $timeline = $this->timeline->normalise(
            is_string($raw['timeline_text'] ?? null) && $raw['timeline_text'] !== ''
                ? $raw['timeline_text']
                : (is_string($raw['required_date'] ?? null) ? $raw['required_date'] : $input),
        );

        $measure = $this->resolveMeasure($raw, $input);
        $quantity = $measure['quantity'];
        $title = $this->resolveTitle($raw['title'] ?? null, $input);

        $draft = [
            'title' => $title,
            // The buyer's own words are kept verbatim as the description. A model
            // rewrite adds nothing and risks changing the specification.
            'description' => Str::limit(trim($input), 5000, ''),
            'category' => $category['category']?->slug,
            'category_name' => $category['category']?->name,
            'city' => $location['city'],
            'state' => $location['state'],
            'quantity' => $quantity,
            'unit' => $measure['unit'],
            'budget_min' => $budget['min'],
            'budget_max' => $budget['max'],
            'currency' => (string) config('marketplace.default_currency', 'INR'),
            'required_date' => $timeline['date'],
            'timeline_text' => $timeline['text'],
            'urgency' => $this->resolveUrgency($raw['urgency'] ?? null, $timeline['urgency']),
            'specifications' => $this->resolveSpecifications($raw['specifications'] ?? null),
        ];

        return [
            'draft' => $draft,
            'confidence' => [
                'category' => $category['confidence'],
                'location' => $location['exists_in_database'] ? 1.0 : 0.5,
                'budget' => $budget['confidence'],
                'quantity' => $quantity === null ? 0.0 : 0.9,
                'required_date' => $timeline['date'] === null ? 0.0 : 0.85,
            ],
            'resolution' => [
                'category' => $category['reason'],
                'location' => $location['reason'],
                'location_known' => $location['exists_in_database'],
            ],
            // Real rows only, for a "did you mean" picker. Empty when the category
            // resolved cleanly.
            'category_options' => collect($category['candidates'])
                ->map(fn ($option): array => ['slug' => $option->slug, 'name' => $option->name])
                ->all(),
            'location_options' => $location['candidates'],
            'missing' => $this->missingFields($draft),
            'questions' => $this->questions($draft, $category),
            'ready_to_draft' => $draft['title'] !== null && $draft['category'] !== null,
            'ready_to_publish' => $this->missingFields($draft) === [],
        ];
    }

    /**
     * The one AI call.
     *
     * Two failure modes, handled differently on purpose:
     *
     *  - **The model answered but the JSON was unusable.** Degrade. The deterministic
     *    parsers can still recover a budget, a date and often a category from the raw
     *    text, so the buyer gets a partly filled form — materially better than an error.
     *
     *  - **The provider was unreachable, disabled, or the quota is spent.** Propagate.
     *    There is nothing to degrade from: with no extracted city or category the
     *    "draft" would be almost empty, and returning 200 with an empty form pretends
     *    the feature worked. A 503 pointing at the manual form is the honest answer, and
     *    it is what lets the frontend hide the AI affordance instead of showing a
     *    useless result.
     *
     * @return array<string, mixed>
     *
     * @throws AiException When AI is unavailable, disabled or over quota.
     */
    private function askModel(string $input, ?User $user): array
    {
        $schema = [
            'title' => 'string — a short title for the requirement, or null',
            'category' => 'string — one category slug from the list, or null',
            'city' => 'string — city named by the user, or null',
            'state' => 'string — state, or null',
            'quantity' => 'number — how many/much, or null',
            'unit' => 'string — unit of measure such as pieces, sq ft, tonnes, hours, or null',
            'budget_text' => 'string — the budget exactly as the user wrote it, e.g. "15 lakh", or null',
            'budget_min' => 'number — lower budget as a plain integer in rupees, or null',
            'budget_max' => 'number — upper budget as a plain integer in rupees, or null',
            'timeline_text' => 'string — the deadline exactly as the user wrote it, e.g. "3 months", or null',
            'required_date' => 'string — YYYY-MM-DD only if an actual date was stated, else null',
            'urgency' => 'string — one of low, normal, high, urgent, or null',
            'specifications' => 'array of short strings — concrete specs mentioned, e.g. "3000 sq ft", "304 grade"',
        ];

        try {
            $decoded = $this->ai->extract(
                input: $input,
                schema: $schema,
                user: $user,
                feature: 'requirement_builder',
                instructions: $this->prompts->extractionInstructions($this->categories->promptCatalogue()),
            );
        } catch (AiInvalidResponseException) {
            // The model replied with something unusable. Degrade — the deterministic
            // layer below still runs and often recovers the budget and the deadline.
            return [];
        }
        // Every other AiException (unavailable, timeout, disabled, quota) propagates to
        // the controller, which answers 503 and names the manual form.

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Budget, with our own parse taking precedence over the model's arithmetic.
     *
     * The model is asked for both the raw phrase and a normalised number. When the two
     * disagree we trust our parse of the phrase, because that is the failure this whole
     * class exists to prevent: "15 lakh" returned as 15.
     *
     * @param  array<string, mixed>  $raw
     * @return array{min: int|null, max: int|null, confidence: float}
     */
    private function resolveBudget(array $raw, string $input): array
    {
        $fromText = $this->amounts->normaliseRange(
            is_string($raw['budget_text'] ?? null) ? $raw['budget_text'] : null
        );

        $modelMin = $this->amounts->normalise($raw['budget_min'] ?? null);
        $modelMax = $this->amounts->normalise($raw['budget_max'] ?? null);

        /*
         * Our own parse of the buyer's raw sentence, run unconditionally.
         *
         * This used to be a last resort, reached only when both the model's number and
         * its quoted phrase were absent — which left a hole. Live testing against a 3B
         * model found it: asked about "budget 8 lakh", the model omitted `budget_text`
         * and returned `budget_max: 8000000`, so there was nothing to cross-check
         * against and a ten-fold error went straight through.
         *
         * Scanning the raw text every time closes that: the phrase the buyer actually
         * typed is always available as a second opinion.
         */
        $scanned = $this->scanBudgetPhrase($input);

        // Prefer, in order: our parse of the model's quoted phrase, our parse of the raw
        // sentence, then the model's own arithmetic. The model's number is the last
        // resort because it is the only one of the three we cannot verify.
        $max = $fromText['max'] ?? $scanned ?? $modelMax;
        $min = $fromText['min'] ?? $modelMin;

        /*
         * Disagreement check. A model figure an order of magnitude away from a
         * deterministic parse of the same sentence is the classic Indian-numbering
         * error — "15 lakh" read as 15, or "8 lakh" read as 80 lakh.
         *
         * Threshold is 10x rather than 100x: the common failure is one scale word
         * misapplied, which is exactly a factor of ten or a hundred, and 10x catches
         * both without flagging ordinary rounding.
         */
        $ourFigure = $fromText['max'] ?? $scanned;
        $suspect = false;

        if ($ourFigure !== null && $modelMax !== null && $modelMax > 0 && $ourFigure > 0) {
            $ratio = max($ourFigure / $modelMax, $modelMax / $ourFigure);

            if ($ratio >= 10) {
                // Ours wins, and confidence drops so the UI flags the field for review
                // rather than accepting a figure we know two sources disagree on.
                $max = $ourFigure;
                $suspect = true;
            }
        }

        if ($min === null && $max === null) {
            return ['min' => null, 'max' => null, 'confidence' => 0.0];
        }

        if ($min !== null && $max !== null && $min > $max) {
            [$min, $max] = [$max, $min];
        }

        return [
            'min' => $min,
            'max' => $max,
            // Highest when the buyer's own phrase was parsed; lower when only the
            // model's unverifiable number was available.
            'confidence' => match (true) {
                $suspect => 0.6,
                $fromText['max'] !== null => 0.9,
                $scanned !== null => 0.8,
                default => 0.55,
            },
        ];
    }

    /**
     * Look for a budget phrase in the raw sentence.
     *
     * Anchored on an explicit money cue — a currency marker, the word "budget", or an
     * Indian scale word — so "500 chairs" is never mistaken for a ₹500 budget. That
     * mistake would silently cap the requirement at five hundred rupees and match it to
     * nobody.
     *
     * The budget-keyword window is bounded to the amount that follows it rather than a
     * blind character count, so "budget 8 lakh, 2 mahine mein" cannot have the "2" from
     * the timeline pulled into the figure.
     */
    private function scanBudgetPhrase(string $input): ?int
    {
        $scale = '(?:lakhs?|lacs?|laakh|crores?|cr|k|thousand|hazaar)';

        $patterns = [
            // "budget around 15 lakh", "budget: 2.5cr", "budget 8 lakh"
            '/\bbudget\b[^\d\n]{0,20}[\d,.]+\s*'.$scale.'?/iu',
            // "₹15 lakh", "Rs 2,50,000"
            '/(?:₹|\brs\.?\b|\binr\b)\s*[\d,.]+\s*'.$scale.'?/iu',
            // A number carrying an Indian scale word anywhere in the sentence.
            '/\b[\d,.]+\s*(?:lakhs?|lacs?|laakh|crores?|cr)\b/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $input, $matches) === 1) {
                $amount = $this->amounts->normalise($matches[0]);

                if ($amount !== null && $amount > 0) {
                    return $amount;
                }
            }
        }

        return null;
    }

    /**
     * Quantity and unit, with a deterministic fallback when the model misses them.
     *
     * Added after live testing: a small local model (llama3.2:3b) reliably extracted the
     * city, budget and timeline from "office interior for around 3000 sq ft in Noida" but
     * dropped the quantity. Budget and dates already had deterministic recovery, and
     * quantity is just as parseable — so it gets the same treatment rather than leaving
     * the feature dependent on model size.
     *
     * @param  array<string, mixed>  $raw
     * @return array{quantity: float|null, unit: string|null}
     */
    private function resolveMeasure(array $raw, string $input): array
    {
        $quantity = $this->resolveQuantity($raw['quantity'] ?? null);
        $unit = $this->resolveUnit($raw['unit'] ?? null);

        if ($quantity !== null) {
            // The model got it. Still try to recover a missing unit from the text.
            return ['quantity' => $quantity, 'unit' => $unit ?? $this->scanMeasure($input)['unit']];
        }

        $scanned = $this->scanMeasure($input);

        return [
            'quantity' => $scanned['quantity'],
            'unit' => $unit ?? $scanned['unit'],
        ];
    }

    /**
     * Find a "<number> <unit>" pair in the raw text.
     *
     * The unit list is a *recognition* aid, not a whitelist — it exists so the scanner can
     * tell "3000 sq ft" from "3 months" and from a budget figure. Anything the model
     * extracts is still accepted verbatim, so an industry with a unit nobody anticipated
     * is unaffected.
     *
     * Deliberately skips numbers that are part of a money phrase or a duration, which are
     * the two ways a naive scanner turns a budget or a deadline into a quantity.
     *
     * @return array{quantity: float|null, unit: string|null}
     */
    private function scanMeasure(string $input): array
    {
        // Ordered longest-first so "sq ft" wins over "ft" and "sq mtr" over "mtr".
        $units = [
            'square feet', 'square foot', 'square metre', 'square meter', 'sq\.?\s?ft', 'sq\.?\s?m(?:tr)?', 'sqft', 'sqm',
            'running (?:feet|metre|meter)', 'linear (?:feet|metre|meter)',
            'metric tonnes?', 'tonnes?', 'tons?', 'quintals?', 'kilograms?', 'kgs?', 'grams?',
            'litres?', 'liters?', 'ltrs?', 'gallons?',
            'pieces?', 'pcs?', 'nos?\.?', 'units?', 'items?', 'sets?', 'pairs?',
            'boxes', 'box', 'cartons?', 'bags?', 'rolls?', 'sheets?', 'bundles?', 'packs?', 'packets?',
            'seats?', 'licen[cs]es?', 'users?', 'workstations?', 'desks?', 'chairs?',
            'hours?', 'man-?hours?', 'days?', 'shifts?', 'visits?', 'trips?',
            'rooms?', 'floors?', 'sites?', 'branches?', 'vehicles?', 'machines?',
            'kilometres?', 'kilometers?', 'kms?', 'metres?', 'meters?', 'feet', 'inches',
        ];

        $pattern = '/(?<!₹)(?<!\$)\b(\d[\d,]*(?:\.\d+)?)\s*('.implode('|', $units).')\b/iu';

        if (preg_match_all($pattern, $input, $matches, PREG_SET_ORDER) === 0) {
            return ['quantity' => null, 'unit' => null];
        }

        foreach ($matches as $match) {
            $unit = trim(preg_replace('/\s+/', ' ', $match[2]));

            // A duration is a timeline, not a quantity — TimelineNormaliser owns those.
            if (preg_match('/^(?:hours?|days?|months?|years?|weeks?)$/i', $unit) === 1) {
                continue;
            }

            $quantity = $this->resolveQuantity(str_replace(',', '', $match[1]));

            if ($quantity !== null) {
                return ['quantity' => $quantity, 'unit' => Str::limit($unit, 30, '')];
            }
        }

        return ['quantity' => null, 'unit' => null];
    }

    /** Quantity, with a ceiling that rejects a runaway parse rather than storing it. */
    private function resolveQuantity(mixed $value): ?float
    {
        if ($value === null || is_bool($value) || $value === '') {
            return null;
        }

        if (is_string($value)) {
            // "3000 sq ft" → 3000; the unit is captured separately.
            if (preg_match('/[\d,.]+/', $value, $matches) !== 1) {
                return null;
            }

            $value = str_replace(',', '', $matches[0]);
        }

        if (! is_numeric($value)) {
            return null;
        }

        $quantity = (float) $value;

        return $quantity > 0 && $quantity < 1_000_000_000 ? $quantity : null;
    }

    private function resolveUnit(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        // Free text on purpose — units are as varied as the industries here, and a
        // whitelist would reject "running metre" or "per seat per month".
        return Str::limit(trim($value), 30, '');
    }

    /** Fall back to the buyer's first sentence rather than a generated title. */
    private function resolveTitle(mixed $value, string $input): ?string
    {
        if (is_string($value) && trim($value) !== '') {
            return Str::limit(trim($value), 120, '');
        }

        $firstSentence = preg_split('/(?<=[.!?])\s+/u', trim($input))[0] ?? trim($input);

        return $firstSentence === '' ? null : Str::limit($firstSentence, 120, '');
    }

    /** Model urgency if it is one of ours, else the interval-derived one. */
    private function resolveUrgency(mixed $value, ?string $derived): string
    {
        $allowed = ['low', 'normal', 'high', 'urgent'];

        if (is_string($value) && in_array(mb_strtolower(trim($value)), $allowed, true)) {
            return mb_strtolower(trim($value));
        }

        return $derived !== null && in_array($derived, $allowed, true) ? $derived : 'normal';
    }

    /**
     * @return array<int, string>
     */
    private function resolveSpecifications(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->filter(fn ($item): bool => is_scalar($item) && trim((string) $item) !== '')
            ->map(fn ($item): string => Str::limit(trim((string) $item), 120, ''))
            ->unique()
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<int, string>
     */
    private function missingFields(array $draft): array
    {
        return collect(self::PUBLISH_REQUIRED)
            ->filter(fn (string $field): bool => $draft[$field] === null || $draft[$field] === '')
            ->values()
            ->all();
    }

    /**
     * Questions worth asking, in priority order and capped at two.
     *
     * The cap is the design decision. An assistant that asks for six fields at once is
     * a form with extra steps, and the buyer abandons it — so it asks for what blocks
     * matching most and lets the review screen collect the rest.
     *
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>  $category
     * @return array<int, string>
     */
    private function questions(array $draft, array $category): array
    {
        $questions = [];

        if ($draft['category'] === null) {
            $options = collect($category['candidates'])->map(fn ($c): string => $c->name);

            $questions[] = $options->isNotEmpty()
                ? sprintf('Which of these fits best: %s?', $options->implode(', '))
                : 'Which category does this fall under? I could not match it to one on the marketplace.';
        }

        if ($draft['city'] === null) {
            $questions[] = 'Which city should this be delivered in or carried out in?';
        }

        if ($draft['quantity'] === null && count($questions) < 2) {
            $questions[] = 'How much or how many do you need?';
        }

        if ($draft['required_date'] === null && count($questions) < 2) {
            $questions[] = 'By when do you need this?';
        }

        return array_slice($questions, 0, 2);
    }
}
