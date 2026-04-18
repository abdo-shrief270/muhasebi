<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BlogTagResource\Pages;

use App\Filament\Admin\Resources\BlogTagResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBlogTag extends CreateRecord
{
    protected static string $resource = BlogTagResource::class;
}
