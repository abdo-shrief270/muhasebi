<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ProfitDistributionResource\Pages;

use App\Filament\Admin\Resources\ProfitDistributionResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditProfitDistribution extends EditRecord
{
    protected static string $resource = ProfitDistributionResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
