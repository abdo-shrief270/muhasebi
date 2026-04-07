<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Domain\Tenant\Models\Tenant */
class LandingPageSettingsResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'tagline' => $this->tagline,
            'description' => $this->description,
            'primary_color' => $this->primary_color,
            'secondary_color' => $this->secondary_color,
            'logo_path' => $this->logo_path,
            'hero_image_path' => $this->hero_image_path,
            'is_landing_page_active' => $this->is_landing_page_active,
            'landing_page_url' => url("/company/{$this->slug}"),
        ];
    }
}
