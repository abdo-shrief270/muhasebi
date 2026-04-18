<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SlugRedirectResource\Pages;

use App\Filament\Admin\Resources\SlugRedirectResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSlugRedirects extends ListRecords
{
    protected static string $resource = SlugRedirectResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
