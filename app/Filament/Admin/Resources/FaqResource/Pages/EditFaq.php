<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\FaqResource\Pages;

use App\Filament\Admin\Resources\FaqResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditFaq extends EditRecord
{
    protected static string $resource = FaqResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
