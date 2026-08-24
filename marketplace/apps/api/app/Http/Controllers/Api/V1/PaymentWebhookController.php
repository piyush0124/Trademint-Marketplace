<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PaymentWebhookEvent;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Provider webhook endpoint (Phase 8).
 *
 * Unauthenticated by necessity — providers cannot hold a session — so the
 * authentication *is* the signature check, performed by the gateway against the
 * raw request body inside PaymentService. Nothing is trusted before it passes.
 *
 * Deliberate response policy: every outcome returns 200 with a neutral body, apart
 * from an outright rejection, which returns 400. Providers retry on non-2xx, so
 * returning an error for "duplicate" or "not actionable" would cause endless
 * redelivery of events we have correctly decided to ignore. The detail lives in the
 * stored event record, not in the response — a caller probing this endpoint learns
 * nothing about which references exist.
 */
class PaymentWebhookController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    public function handle(Request $request, string $provider): JsonResponse
    {
        // The raw body, not the parsed array: signatures are computed over the
        // exact bytes the provider sent.
        $event = $this->payments->handleWebhook(
            $provider,
            $request->getContent(),
            $request->headers->all(),
        );

        if ($event->status === PaymentWebhookEvent::STATUS_REJECTED) {
            return response()->json([
                'success' => false,
                'message' => 'Webhook rejected.',
            ], 400);
        }

        return response()->json([
            'success' => true,
            'message' => 'Webhook received.',
        ]);
    }
}
