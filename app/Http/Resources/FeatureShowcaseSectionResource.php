<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Cms\Models\FeatureShowcaseSection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public marketing-page payload — bilingual on every text field so the
 * SPA renders the active locale without a second round-trip.
 *
 * @mixin FeatureShowcaseSection
 */
class FeatureShowcaseSectionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'icon' => $this->icon,
            'accent' => $this->accent,
            'title_en' => $this->title_en,
            'title_ar' => $this->title_ar,
            'subtitle_en' => $this->subtitle_en,
            'subtitle_ar' => $this->subtitle_ar,
            'sort_order' => $this->sort_order,
            'items' => FeatureShowcaseItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
