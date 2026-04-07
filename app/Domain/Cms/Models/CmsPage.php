<?php

namespace App\Domain\Cms\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable(['slug', 'title_ar', 'title_en', 'content_ar', 'content_en', 'meta_description_ar', 'meta_description_en', 'is_published'])]
class CmsPage extends Model
{
    use LogsActivity;
    use SoftDeletes;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['slug', 'title_ar', 'title_en', 'is_published'])
            ->logOnlyDirty()
            ->setDescriptionForEvent(fn (string $eventName) => "CMS page {$eventName}");
    }

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
        ];
    }

    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }

    public function scopeBySlug($query, string $slug)
    {
        return $query->where('slug', $slug);
    }
}
