<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Cms\Models\FeatureShowcaseItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FeatureShowcaseItem */
class FeatureShowcaseItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'icon' => $this->icon,
            'title_en' => $this->title_en,
            'title_ar' => $this->title_ar,
            'description_en' => $this->description_en,
            'description_ar' => $this->description_ar,
            'badge_en' => $this->badge_en,
            'badge_ar' => $this->badge_ar,
            'sort_order' => $this->sort_order,
        ];
    }
}
