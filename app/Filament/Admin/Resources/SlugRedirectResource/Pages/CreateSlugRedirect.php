<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SlugRedirectResource\Pages;

use App\Filament\Admin\Resources\SlugRedirectResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSlugRedirect extends CreateRecord
{
    protected static string $resource = SlugRedirectResource::class;
}
