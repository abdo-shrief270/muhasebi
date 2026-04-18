<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\InvestorResource\Pages;

use App\Filament\Admin\Resources\InvestorResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewInvestor extends ViewRecord
{
    protected static string $resource = InvestorResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
