<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ContactSubmissionResource\Pages;

use App\Filament\Admin\Resources\ContactSubmissionResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditContactSubmission extends EditRecord
{
    protected static string $resource = ContactSubmissionResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
