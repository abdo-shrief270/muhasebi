<?php

declare(strict_types=1);

namespace App\Domain\FixedAssets\Models;

use App\Domain\Shared\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Table('asset_categories')]
#[Fillable([
    'tenant_id',
    'name_ar',
    'name_en',
    'depreciation_expense_account_id',
    'accumulated_depreciation_account_id',
    'default_useful_life_years',
    'default_depreciation_method',
])]
class AssetCategory extends Model
{
    use BelongsToTenant;
    use SoftDeletes;

    public function assets(): HasMany
    {
        return $this->hasMany(FixedAsset::class, 'category_id');
    }
}
