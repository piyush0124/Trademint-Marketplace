<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Resolves a gateway by key from config('payments.providers').
 *
 * The single place a provider name is turned into an implementation. Nothing else
 * in the codebase mentions a specific provider, so adding one is: write a driver,
 * add a config entry, set env vars.
 */
class PaymentGatewayManager
{
    /** @var array<string, PaymentGateway> */
    private array $resolved = [];

    public function default(): PaymentGateway
    {
        return $this->driver((string) config('payments.default', 'manual'));
    }

    public function driver(string $provider): PaymentGateway
    {
        if (isset($this->resolved[$provider])) {
            return $this->resolved[$provider];
        }

        $config = config("payments.providers.$provider");

        if (! is_array($config) || empty($config['driver'])) {
            throw new InvalidArgumentException(sprintf('Payment provider [%s] is not configured.', $provider));
        }

        $class = $config['driver'];
        $gateway = new $class($config);

        if (! $gateway instanceof PaymentGateway) {
            throw new InvalidArgumentException(sprintf('Payment driver [%s] must implement PaymentGateway.', $class));
        }

        return $this->resolved[$provider] = $gateway;
    }

    /** Same as driver(), but surfaces an unknown provider as a 422 for API callers. */
    public function requireDriver(?string $provider): PaymentGateway
    {
        if ($provider === null) {
            return $this->default();
        }

        if (! $this->isConfigured($provider)) {
            throw ValidationException::withMessages([
                'provider' => ['That payment method is not available.'],
            ]);
        }

        return $this->driver($provider);
    }

    public function isConfigured(string $provider): bool
    {
        return is_array(config("payments.providers.$provider"));
    }

    /**
     * Providers offered to a customer, with their labels. Secrets are never
     * included — only the key, the label and whether it settles offline.
     *
     * @return array<int, array{provider: string, label: string, supports_webhooks: bool, is_default: bool}>
     */
    public function available(): array
    {
        $default = (string) config('payments.default', 'manual');

        return collect((array) config('payments.providers', []))
            ->map(fn (array $config, string $key) => [
                'provider' => $key,
                'label' => $config['label'] ?? $key,
                'supports_webhooks' => (bool) ($config['supports_webhooks'] ?? false),
                'is_default' => $key === $default,
            ])
            ->values()
            ->all();
    }
}
