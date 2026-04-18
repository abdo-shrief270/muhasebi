<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ProfitDistributionResource\Pages;

use App\Filament\Admin\Resources\ProfitDistributionResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProfitDistributions extends ListRecords
{
    protected static string $resource = ProfitDistributionResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
