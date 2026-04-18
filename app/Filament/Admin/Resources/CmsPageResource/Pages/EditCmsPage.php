<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CmsPageResource\Pages;

use App\Filament\Admin\Resources\CmsPageResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCmsPage extends EditRecord
{
    protected static string $resource = CmsPageResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
