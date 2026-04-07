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
    'description_pattern',
    'priority',
    'assignment_source',
])]
class EtaItemCodeMapping extends Model
{
    use BelongsToTenant;

    /** @var array<string, mixed> */
    protected $attributes = [
        'priority' => 0,
        'assignment_source' => 'manual',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
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

    public function scopeByProduct(Builder $query, int $productId): Builder
    {
        return $query->where('product_id', $productId);
    }

    public function scopeByPattern(Builder $query): Builder
    {
        return $query->whereNotNull('description_pattern');
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderByDesc('priority');
    }
}
