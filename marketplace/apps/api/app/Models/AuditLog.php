<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An append-only audit entry.
 *
 * Immutability is enforced at the model level: an audit trail that can be edited is
 * not an audit trail. Nothing sensitive is stored — the metadata bag is for small
 * contextual facts (a status transition, a reason), never request payloads.
 */
class AuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'actor_role',
        'action',
        'entity_type',
        'entity_id',
        'entity_reference',
        'ip_address',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new \RuntimeException('Audit log entries are append-only.');
        });

        static::deleting(function (): void {
            throw new \RuntimeException('Audit log entries cannot be deleted.');
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForEntity(Builder $query, string $type, int $id): Builder
    {
        return $query->where('entity_type', $type)->where('entity_id', $id);
    }
}
