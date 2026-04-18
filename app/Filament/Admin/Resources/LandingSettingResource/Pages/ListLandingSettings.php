<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\LandingSettingResource\Pages;

use App\Filament\Admin\Resources\LandingSettingResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLandingSettings extends ListRecords
{
    protected static string $resource = LandingSettingResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
