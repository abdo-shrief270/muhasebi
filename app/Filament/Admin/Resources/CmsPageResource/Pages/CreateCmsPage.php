<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CmsPageResource\Pages;

use App\Filament\Admin\Resources\CmsPageResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCmsPage extends CreateRecord
{
    protected static string $resource = CmsPageResource::class;
}
