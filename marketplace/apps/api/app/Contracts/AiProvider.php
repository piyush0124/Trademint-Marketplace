<?php

namespace App\Contracts;

use App\Exceptions\Ai\AiInvalidResponseException;
use App\Exceptions\Ai\AiUnavailableException;
use App\Services\Ai\Data\AiChatRequest;
use App\Services\Ai\Data\AiChatResponse;

/**
 * The seam between the marketplace and any AI vendor.
 *
 * Everything above this line — AiService, the tools, the controllers, the whole
 * frontend — is written against these four methods. Adding a provider means writing
 * one class; it never means touching business logic. That is the point: the AI
 * vendor landscape changes faster than this marketplace should have to.
 *
 * Implementations must:
 *
 *  - throw only `App\Exceptions\Ai\*`, never a raw HTTP or JSON exception, so
 *    callers handle one failure vocabulary;
 *  - keep credentials out of exception messages and logs;
 *  - normalise the vendor envelope into `AiChatResponse` so nothing downstream
 *    learns the vendor's shape.
 */
interface AiProvider
{
    /** Stable key used in config, logs and the request ledger. */
    public function name(): string;

    /**
     * One chat completion.
     *
     * @throws AiUnavailableException network, timeout, auth, rate limit
     * @throws AiInvalidResponseException answered, but unusable
     */
    public function chat(AiChatRequest $request): AiChatResponse;

    /**
     * Embed one or more strings.
     *
     * Present on the interface even though no phase here builds semantic search,
     * because the alternative is a second interface later and a migration between
     * them. Providers without an embeddings endpoint report false from
     * `supportsEmbeddings()` and throw if called anyway.
     *
     * @param  array<int, string>  $texts
     * @return array<int, array<int, float>> One vector per input, in order.
     *
     * @throws AiUnavailableException
     */
    public function embed(array $texts, ?string $model = null): array;

    public function supportsEmbeddings(): bool;

    /**
     * Cheap reachability probe.
     *
     * Must never throw: this is called to decide whether to *show* an AI button, so
     * a provider being down has to read as `false`, not as an exception on a page
     * render. Results are cached by the manager.
     */
    public function isAvailable(): bool;

    /** Model used when the caller names none. */
    public function defaultModel(): string;
}
