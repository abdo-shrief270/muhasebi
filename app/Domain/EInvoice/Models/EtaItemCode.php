<?php

declare(strict_types=1);

namespace App\Domain\EInvoice\Models;

use App\Domain\Shared\Traits\BelongsToTenant;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table('eta_item_codes')]
#[Fillable([
    'tenant_id',
    'code_type',
    'item_code',
    'description',
    'description_ar',
    'unit_type',
    'default_tax_type',
    'default_tax_subtype',
    'is_active',
])]
class EtaItemCode extends Model
{
    use BelongsToTenant;

    /** @var array<string, mixed> */
    protected $attributes = [
        'code_type' => 'EGS',
        'unit_type' => 'EA',
        'default_tax_type' => 'T1',
        'default_tax_subtype' => 'V009',
        'is_active' => true,
    ];

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

    // ──────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->where(function (Builder $q) use ($term): void {
            $q->where('item_code', 'ilike', "%{$term}%")
                ->orWhere('description', 'ilike', "%{$term}%")
                ->orWhere('description_ar', 'ilike', "%{$term}%");
        });
    }
}
