<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ProfitDistributionResource\Pages;

use App\Filament\Admin\Resources\ProfitDistributionResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewProfitDistribution extends ViewRecord
{
    protected static string $resource = ProfitDistributionResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
