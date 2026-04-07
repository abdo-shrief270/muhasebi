<?php

namespace App\Domain\Cms\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['old_slug', 'new_slug', 'type'])]
class SlugRedirect extends Model
{
    public static function track(string $oldSlug, string $newSlug, string $type): void
    {
        if ($oldSlug === $newSlug) {
            return;
        }

        // Create redirect from old → new
        static::updateOrCreate(
            ['old_slug' => $oldSlug, 'type' => $type],
            ['new_slug' => $newSlug],
        );

        // Update any existing redirects that pointed to the old slug
        static::where('new_slug', $oldSlug)
            ->where('type', $type)
            ->update(['new_slug' => $newSlug]);
    }

    public static function resolve(string $slug, string $type): ?string
    {
        return static::where('old_slug', $slug)
            ->where('type', $type)
            ->value('new_slug');
    }
}
