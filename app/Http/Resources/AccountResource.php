<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Accounting\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Account */
class AccountResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'type' => $this->type,
            'normal_balance' => $this->normal_balance,
            'is_active' => $this->is_active,
            'is_group' => $this->is_group,
            'level' => $this->level,
            'description' => $this->description,
            'currency' => $this->currency,
            'parent_id' => $this->parent_id,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'children' => AccountResource::collection($this->whenLoaded('children')),
            'parent' => new AccountResource($this->whenLoaded('parent')),
        ];
    }
}
