<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\TestimonialResource\Pages;

use App\Filament\Admin\Resources\TestimonialResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTestimonial extends CreateRecord
{
    protected static string $resource = TestimonialResource::class;
}
