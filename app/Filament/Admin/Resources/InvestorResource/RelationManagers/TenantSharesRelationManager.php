<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\InvestorResource\RelationManagers;

use App\Domain\Tenant\Models\Tenant;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TenantSharesRelationManager extends RelationManager
{
    protected static string $relationship = 'tenantShares';

    protected static ?string $title = 'Tenant Ownership Stakes';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\Select::make('tenant_id')
                ->label('Tenant')
                ->options(fn (): array => Tenant::query()->pluck('name', 'id')->all())
                ->searchable()
                ->required()
                ->preload(),
            Forms\Components\TextInput::make('ownership_percentage')
                ->label('Ownership %')
                ->numeric()
                ->required()
                ->minValue(0)
                ->maxValue(100)
                ->suffix('%'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('tenant.name')
            ->columns([
                TextColumn::make('tenant.name')
                    ->label('Tenant')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('ownership_percentage')
                    ->label('Ownership')
                    ->suffix('%')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Added')
                    ->since()
                    ->toggleable(),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
