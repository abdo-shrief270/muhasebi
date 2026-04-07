<?php

namespace App\Domain\Blog\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['slug', 'name_ar', 'name_en', 'description_ar', 'description_en', 'sort_order'])]
class BlogCategory extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    public function posts(): HasMany
    {
        return $this->hasMany(BlogPost::class, 'category_id');
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }
}
