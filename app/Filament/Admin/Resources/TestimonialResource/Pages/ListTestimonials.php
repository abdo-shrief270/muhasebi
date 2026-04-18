<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\TestimonialResource\Pages;

use App\Filament\Admin\Resources\TestimonialResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTestimonials extends ListRecords
{
    protected static string $resource = TestimonialResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
