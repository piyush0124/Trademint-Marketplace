<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

/**
 * Phase 10 — audit logging for actions that carry consequence.
 *
 * Deliberately explicit rather than an observer on every model: an audit trail is
 * most useful when it records *decisions* (an admin verified a business, a review
 * was hidden, a refund was issued), not every column that changed. Blanket model
 * auditing produces noise that nobody reads.
 *
 * Privacy: the entry holds the acting user id, their role, the entity, an IP and a
 * small metadata bag. No request bodies, no credentials, no customer contact
 * details — enough to answer "who did this", nothing more.
 */
class AuditLogger
{
    public function log(string $action, ?Model $entity = null, ?User $actor = null, array $metadata = []): AuditLog
    {
        return AuditLog::create([
            'user_id' => $actor?->id,
            'actor_role' => $actor?->role?->value,
            'action' => $action,
            'entity_type' => $entity !== null ? class_basename($entity) : null,
            'entity_id' => $entity?->getKey(),
            'entity_reference' => $this->referenceFor($entity),
            // Truthful about proxies but never fabricated; null when unavailable
            // (e.g. a console command).
            'ip_address' => Request::instance()?->ip(),
            'metadata' => $this->sanitise($metadata),
        ]);
    }

    /**
     * A human-readable handle for the entity, so an audit list is readable without
     * joining half the schema.
     */
    private function referenceFor(?Model $entity): ?string
    {
        if ($entity === null) {
            return null;
        }

        foreach (['order_number', 'quote_number', 'payment_reference', 'refund_reference', 'reference', 'slug', 'uuid'] as $attribute) {
            if (! empty($entity->{$attribute})) {
                return (string) $entity->{$attribute};
            }
        }

        return null;
    }

    /**
     * Keep metadata small and safe: scalars only, keys that look sensitive dropped
     * outright, long strings truncated.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function sanitise(array $metadata): array
    {
        $blocked = ['password', 'token', 'secret', 'signature', 'card', 'cvv', 'authorization'];

        return collect($metadata)
            ->reject(function ($value, $key) use ($blocked): bool {
                $lowerKey = strtolower((string) $key);

                foreach ($blocked as $needle) {
                    if (str_contains($lowerKey, $needle)) {
                        return true;
                    }
                }

                return ! is_scalar($value) && $value !== null;
            })
            ->map(fn ($value) => is_string($value) ? Str::limit($value, 300) : $value)
            ->all();
    }
}
