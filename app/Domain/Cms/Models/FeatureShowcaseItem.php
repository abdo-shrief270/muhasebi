<?php

declare(strict_types=1);

namespace App\Domain\Cms\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $section_id
 * @property string|null $icon
 * @property string $title_en
 * @property string $title_ar
 * @property string $description_en
 * @property string $description_ar
 * @property string|null $badge_en
 * @property string|null $badge_ar
 * @property int $sort_order
 * @property bool $is_active
 * @property-read FeatureShowcaseSection $section
 */
#[Table('feature_showcase_items')]
#[Fillable([
    'section_id',
    'icon',
    'title_en',
    'title_ar',
    'description_en',
    'description_ar',
    'badge_en',
    'badge_ar',
    'sort_order',
    'is_active',
])]
class FeatureShowcaseItem extends Model
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
        'is_active' => true,
        'sort_order' => 0,
    ];

    /** @return BelongsTo<FeatureShowcaseSection, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(FeatureShowcaseSection::class, 'section_id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }
}
