<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BlogCategoryResource\Pages;

use App\Filament\Admin\Resources\BlogCategoryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBlogCategory extends CreateRecord
{
    protected static string $resource = BlogCategoryResource::class;
}
