<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\LandingSettingResource\Pages;

use App\Filament\Admin\Resources\LandingSettingResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditLandingSetting extends EditRecord
{
    protected static string $resource = LandingSettingResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
