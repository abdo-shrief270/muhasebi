<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Domain\Shared\Traits\BelongsToTenant;
use App\Domain\Tenant\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table('auto_approval_rules')]
#[Fillable([
    'tenant_id',
    'entity_type',
    'condition_field',
    'operator',
    'condition_value',
    'auto_action',
    'is_active',
    'created_by',
])]
class AutoApprovalRule extends Model
{
    use BelongsToTenant;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    // ──────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ──────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForEntityType(Builder $query, string $entityType): Builder
    {
        return $query->where('entity_type', $entityType);
    }

    // ──────────────────────────────────────
    // Rule Evaluation
    // ──────────────────────────────────────

    /**
     * Check if a given value matches this rule's condition.
     */
    public function matches(mixed $value): bool
    {
        $conditionValue = $this->condition_value;

        return match ($this->operator) {
            'lt' => bccomp((string) $value, $conditionValue, 2) < 0,
            'lte' => bccomp((string) $value, $conditionValue, 2) <= 0,
            'eq' => bccomp((string) $value, $conditionValue, 2) === 0,
            'gt' => bccomp((string) $value, $conditionValue, 2) > 0,
            'gte' => bccomp((string) $value, $conditionValue, 2) >= 0,
            'in' => in_array((string) $value, explode(',', $conditionValue), true),
            default => false,
        };
    }
}
