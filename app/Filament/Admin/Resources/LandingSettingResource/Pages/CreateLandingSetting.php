<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\LandingSettingResource\Pages;

use App\Filament\Admin\Resources\LandingSettingResource;
use Filament\Resources\Pages\CreateRecord;

class CreateLandingSetting extends CreateRecord
{
    protected static string $resource = LandingSettingResource::class;
}
