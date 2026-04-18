<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BlogTagResource\Pages;

use App\Filament\Admin\Resources\BlogTagResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditBlogTag extends EditRecord
{
    protected static string $resource = BlogTagResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
