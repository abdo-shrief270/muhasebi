<?php

declare(strict_types=1);

namespace App\Domain\Cms\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $slug
 * @property string|null $icon
 * @property string $accent
 * @property string $title_en
 * @property string $title_ar
 * @property string|null $subtitle_en
 * @property string|null $subtitle_ar
 * @property int $sort_order
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, FeatureShowcaseItem> $items
 */
#[Table('feature_showcase_sections')]
#[Fillable([
    'slug',
    'icon',
    'accent',
    'title_en',
    'title_ar',
    'subtitle_en',
    'subtitle_ar',
    'sort_order',
    'is_active',
])]
class FeatureShowcaseSection extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @var array<string, mixed> */
    protected $attributes = [
        'accent' => 'primary',
        'is_active' => true,
        'sort_order' => 0,
    ];

    /** @return HasMany<FeatureShowcaseItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(FeatureShowcaseItem::class, 'section_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function scopeOrdered(Builder $q): Builder
    {
        return $q->orderBy('sort_order')->orderBy('id');
    }
}
