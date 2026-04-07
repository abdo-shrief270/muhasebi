<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use App\Domain\Shared\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table('bank_categorization_rules')]
#[Fillable([
    'tenant_id',
    'pattern',
    'match_type',
    'account_id',
    'vendor_id',
    'priority',
    'use_count',
    'is_active',
    'created_by',
])]
class BankCategorizationRule extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'use_count' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    // ── Relationships ──

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\AccountsPayable\Models\Vendor::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── Scopes ──

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // ── Helpers ──

    /**
     * Check if the given description matches this rule's pattern.
     */
    public function matches(string $description): bool
    {
        $desc = mb_strtolower($description);
        $pattern = mb_strtolower($this->pattern);

        return match ($this->match_type) {
            'exact' => $desc === $pattern,
            'contains' => str_contains($desc, $pattern),
            'starts_with' => str_starts_with($desc, $pattern),
            'regex' => (bool) @preg_match("/{$this->pattern}/iu", $description),
            default => false,
        };
    }
}
