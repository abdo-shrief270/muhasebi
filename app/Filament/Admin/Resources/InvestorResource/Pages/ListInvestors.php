<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\InvestorResource\Pages;

use App\Filament\Admin\Resources\InvestorResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListInvestors extends ListRecords
{
    protected static string $resource = InvestorResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
