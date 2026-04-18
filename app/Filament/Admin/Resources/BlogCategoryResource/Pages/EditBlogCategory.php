<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BlogCategoryResource\Pages;

use App\Filament\Admin\Resources\BlogCategoryResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditBlogCategory extends EditRecord
{
    protected static string $resource = BlogCategoryResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
