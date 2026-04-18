<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BlogCategoryResource\Pages;

use App\Filament\Admin\Resources\BlogCategoryResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBlogCategories extends ListRecords
{
    protected static string $resource = BlogCategoryResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
