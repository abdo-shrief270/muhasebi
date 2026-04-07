<?php

declare(strict_types=1);

namespace App\Domain\EInvoice\Models;

use App\Domain\EInvoice\Enums\ItemCodeAssignmentSource;
use App\Domain\EInvoice\Enums\ItemCodeSyncStatus;
use App\Domain\Shared\Traits\BelongsToTenant;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
    'sync_status',
    'sync_error',
    'synced_at',
    'assignment_source',
    'eta_code_id',
    'parent_code',
    'level',
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
        'sync_status' => 'pending',
        'assignment_source' => 'manual',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sync_status' => ItemCodeSyncStatus::class,
            'assignment_source' => ItemCodeAssignmentSource::class,
            'synced_at' => 'datetime',
            'level' => 'integer',
        ];
    }

    // ──────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function mappings(): HasMany
    {
        return $this->hasMany(EtaItemCodeMapping::class);
    }

    // ──────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeSynced(Builder $query): Builder
    {
        return $query->where('sync_status', ItemCodeSyncStatus::Synced);
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
