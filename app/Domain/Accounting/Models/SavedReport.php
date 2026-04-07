<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use App\Domain\Shared\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table('saved_reports')]
#[Fillable([
    'tenant_id',
    'created_by',
    'name',
    'name_ar',
    'description',
    'config',
    'is_shared',
])]
class SavedReport extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'is_shared' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeVisibleTo($query, int $userId)
    {
        return $query->where(fn ($q) => $q->where('created_by', $userId)->orWhere('is_shared', true));
    }
}
