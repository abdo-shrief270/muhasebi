<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BlogTagResource\Pages;

use App\Filament\Admin\Resources\BlogTagResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBlogTags extends ListRecords
{
    protected static string $resource = BlogTagResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
