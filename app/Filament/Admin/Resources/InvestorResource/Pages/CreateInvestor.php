<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\InvestorResource\Pages;

use App\Filament\Admin\Resources\InvestorResource;
use Filament\Resources\Pages\CreateRecord;

class CreateInvestor extends CreateRecord
{
    protected static string $resource = InvestorResource::class;
}
