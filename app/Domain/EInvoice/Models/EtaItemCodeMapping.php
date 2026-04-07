<?php

declare(strict_types=1);

namespace App\Domain\EInvoice\Models;

use App\Domain\Inventory\Models\Product;
use App\Domain\Shared\Traits\BelongsToTenant;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table('eta_item_code_mappings')]
#[Fillable([
    'tenant_id',
    'eta_item_code_id',
    'product_id',
    'match_type',
    'pattern',
    'priority',
    'is_active',
])]
class EtaItemCodeMapping extends Model
{
    use BelongsToTenant;

    /** @var array<string, mixed> */
    protected $attributes = [
        'match_type' => 'contains',
        'priority' => 0,
        'is_active' => true,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'priority' => 'integer',
        ];
    }

    // ──────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function etaItemCode(): BelongsTo
    {
        return $this->belongsTo(EtaItemCode::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    // ──────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeByPriority(Builder $query): Builder
    {
        return $query->orderByDesc('priority');
    }

    // ──────────────────────────────────────
    // Methods
    // ──────────────────────────────────────

    /**
     * Check if a description matches this mapping's pattern.
     *
     * Supports match types: contains, starts_with, regex.
     */
    public function matches(string $description): bool
    {
        return match ($this->match_type) {
            'contains' => str_contains(
                mb_strtolower($description),
                mb_strtolower($this->pattern),
            ),
            'starts_with' => str_starts_with(
                mb_strtolower($description),
                mb_strtolower($this->pattern),
            ),
            'regex' => (bool) preg_match($this->pattern, $description),
            default => false,
        };
    }
}
