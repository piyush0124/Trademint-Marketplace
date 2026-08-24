<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    |
    | With AI_ENABLED=false the marketplace behaves exactly as it did before this
    | layer existed: the AI endpoints answer 503 with a clear message, the frontend
    | hides its AI affordances, and every manual form, search and matching run keeps
    | working. That is the contract — AI is additive, never load-bearing.
    |
    */

    'enabled' => (bool) env('AI_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Provider selection
    |--------------------------------------------------------------------------
    |
    | Development defaults to Ollama, so the whole AI workflow is testable on a
    | laptop with no paid API key and no third party seeing marketplace data.
    | Switching to a hosted provider in production is an .env change; no business
    | logic moves.
    |
    */

    'provider' => env('AI_PROVIDER', 'ollama'),

    /*
    |--------------------------------------------------------------------------
    | Task tiers
    |--------------------------------------------------------------------------
    |
    | Not every task needs the strongest model. Classification and extraction are
    | cheap, structured jobs; conversation and explanation benefit from a better
    | model. Each tier resolves to a model name per provider, falling back to the
    | provider's default model when unset — so a single AI_MODEL is enough to get
    | started and the tiers are there when cost matters.
    |
    */

    'tiers' => [
        'fast' => env('AI_MODEL_FAST'),
        'default' => env('AI_MODEL'),
        'strong' => env('AI_MODEL_STRONG'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    |
    | No key, URL or model name is hardcoded. Absent configuration makes a provider
    | report itself unavailable rather than throwing at boot, which is what keeps a
    | half-configured deployment serving the marketplace normally.
    |
    */

    'providers' => [

        'ollama' => [
            'driver' => 'ollama',
            'base_url' => env('AI_BASE_URL', 'http://127.0.0.1:11434'),
            'model' => env('AI_MODEL', 'llama3.1'),
            'embedding_model' => env('AI_EMBEDDING_MODEL', 'nomic-embed-text'),
            /*
             * Local inference on CPU is slow, and this is not a hosted-API timeout.
             *
             * 300s rather than 120s because the *first* call after the model is evicted
             * pays the full load — measured at well over 120s for an 8B model on CPU.
             * A warm call is a fraction of this. Combined with `keep_alive` below, only
             * the very first request after a restart should approach it.
             */
            'timeout' => (int) env('AI_TIMEOUT', 300),
            // How long Ollama keeps the model in memory after a call. Trades idle RAM for
            // not paying the load cost on every question.
            'keep_alive' => env('AI_KEEP_ALIVE', '30m'),
            // Local compute is free. Cost tracking still runs, it just totals zero.
            'cost_per_1k_prompt_tokens' => (float) env('AI_COST_PROMPT', 0),
            'cost_per_1k_completion_tokens' => (float) env('AI_COST_COMPLETION', 0),
        ],

        'openai' => [
            'driver' => 'openai',
            'base_url' => env('AI_BASE_URL', 'https://api.openai.com'),
            'api_key' => env('AI_API_KEY'),
            'model' => env('AI_MODEL', 'gpt-4o-mini'),
            'embedding_model' => env('AI_EMBEDDING_MODEL', 'text-embedding-3-small'),
            'timeout' => (int) env('AI_TIMEOUT', 60),
            // Deliberately unset by default: pricing changes and a stale number in
            // config is worse than an honest null. Set from your own billing page.
            'cost_per_1k_prompt_tokens' => (float) env('AI_COST_PROMPT', 0),
            'cost_per_1k_completion_tokens' => (float) env('AI_COST_COMPLETION', 0),
        ],

        'gemini' => [
            'driver' => 'gemini',
            'base_url' => env('AI_BASE_URL', 'https://generativelanguage.googleapis.com'),
            'api_key' => env('AI_API_KEY'),
            'model' => env('AI_MODEL', 'gemini-1.5-flash'),
            'embedding_model' => env('AI_EMBEDDING_MODEL', 'text-embedding-004'),
            'timeout' => (int) env('AI_TIMEOUT', 60),
            'cost_per_1k_prompt_tokens' => (float) env('AI_COST_PROMPT', 0),
            'cost_per_1k_completion_tokens' => (float) env('AI_COST_COMPLETION', 0),
        ],

        'claude' => [
            'driver' => 'claude',
            'base_url' => env('AI_BASE_URL', 'https://api.anthropic.com'),
            'api_key' => env('AI_API_KEY'),
            'model' => env('AI_MODEL', 'claude-sonnet-4-20250514'),
            // Anthropic has no embeddings endpoint. Declared null rather than
            // pointed at something that would 404 at runtime.
            'embedding_model' => null,
            'anthropic_version' => env('AI_ANTHROPIC_VERSION', '2023-06-01'),
            'timeout' => (int) env('AI_TIMEOUT', 60),
            'cost_per_1k_prompt_tokens' => (float) env('AI_COST_PROMPT', 0),
            'cost_per_1k_completion_tokens' => (float) env('AI_COST_COMPLETION', 0),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Feature flags
    |--------------------------------------------------------------------------
    |
    | Each AI capability can be switched off on its own. If the requirement builder
    | starts producing nonsense at 2am you disable that one flag; natural search,
    | the assistants and the whole marketplace keep running.
    |
    */

    'features' => [
        'assistant' => (bool) env('AI_FEATURE_ASSISTANT', true),
        'requirement_builder' => (bool) env('AI_FEATURE_REQUIREMENT_BUILDER', true),
        'search' => (bool) env('AI_FEATURE_SEARCH', true),
        'matching' => (bool) env('AI_FEATURE_MATCHING', true),
        'quote_comparison' => (bool) env('AI_FEATURE_QUOTE_COMPARISON', true),
        'listing_generator' => (bool) env('AI_FEATURE_LISTING_GENERATOR', true),
        'response_assistant' => (bool) env('AI_FEATURE_RESPONSE_ASSISTANT', true),
        'admin_copilot' => (bool) env('AI_FEATURE_ADMIN_COPILOT', true),
        'demand_insights' => (bool) env('AI_FEATURE_DEMAND_INSIGHTS', true),
        // Off by default: semantic search needs an embedding store that these
        // phases do not build. The switch exists so the code path can be enabled
        // once one does.
        'semantic_search' => (bool) env('AI_FEATURE_SEMANTIC_SEARCH', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    |
    | Two independent brakes. `requests_per_day` is a quota counted from the
    | ai_requests ledger and survives restarts; `rate_limit` is a burst limiter that
    | stops a script hammering the endpoint within a minute. Abuse tends to look
    | like one or the other, rarely both.
    |
    */

    'limits' => [
        'requests_per_day' => [
            'customer' => (int) env('AI_MAX_REQUESTS_PER_DAY_CUSTOMER', 100),
            'owner' => (int) env('AI_MAX_REQUESTS_PER_DAY_OWNER', 200),
            'admin' => (int) env('AI_MAX_REQUESTS_PER_DAY_ADMIN', 500),
        ],

        // Per-minute burst, per user.
        'rate_limit' => (int) env('AI_RATE_LIMIT_PER_MINUTE', 12),

        // Tool calls the model may chain inside a single user turn. Without a cap a
        // confused model can loop tools until the request times out.
        'max_tool_calls_per_turn' => (int) env('AI_MAX_TOOL_CALLS_PER_TURN', 5),

        // How many prior turns are replayed as context. Directly controls cost, and
        // an assistant does not need the whole thread to answer the next question.
        'context_messages' => (int) env('AI_CONTEXT_MESSAGES', 12),

        // Hard ceiling on a single user message, before it reaches the provider.
        'max_input_characters' => (int) env('AI_MAX_INPUT_CHARACTERS', 4000),

        // Minutes a proposed write action stays confirmable.
        'action_ttl_minutes' => (int) env('AI_ACTION_TTL_MINUTES', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Generation defaults
    |--------------------------------------------------------------------------
    |
    | Low temperature on purpose. This assistant reports facts retrieved from the
    | database; creative variance is a bug here, not a feature. Extraction drops it
    | to zero for determinism.
    |
    */

    'generation' => [
        'temperature' => (float) env('AI_TEMPERATURE', 0.2),
        'extraction_temperature' => 0.0,
        'max_tokens' => (int) env('AI_MAX_TOKENS', 1200),
    ],

    /*
    |--------------------------------------------------------------------------
    | Availability probe cache
    |--------------------------------------------------------------------------
    |
    | Whether the provider is reachable is cached briefly so that showing the AI
    | button on a dashboard does not mean a network round trip per page view. Short
    | enough that a provider coming back up is noticed quickly.
    |
    */

    'availability_cache_seconds' => (int) env('AI_AVAILABILITY_CACHE_SECONDS', 30),

];
