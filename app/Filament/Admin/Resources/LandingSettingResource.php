<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\Cms\Models\LandingSetting;
use App\Filament\Admin\Resources\LandingSettingResource\Pages;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LandingSettingResource extends Resource
{
    protected static ?string $model = LandingSetting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaintBrush;

    protected static string|\UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $recordTitleAttribute = 'section';

    protected static ?int $navigationSort = 170;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Section')
                ->schema([
                    Forms\Components\TextInput::make('section')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->helperText('Section key referenced by the landing page (e.g. "hero", "features").')
                        ->maxLength(100),
                    Forms\Components\KeyValue::make('data')
                        ->label('Data')
                        ->keyLabel('Key')
                        ->valueLabel('Value')
                        ->addActionLabel('Add field')
                        ->helperText('Stored as JSON. Nest complex structures by using dot-notation keys if helpful.')
                        ->reorderable()
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('section')->badge()->color('primary')->searchable()->sortable(),
                TextColumn::make('data')
                    ->label('Keys')
                    ->formatStateUsing(fn ($state): string => is_array($state) ? implode(', ', array_keys($state)) : '—')
                    ->limit(80),
                TextColumn::make('updated_at')->label('Updated')->since(),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])])
            ->defaultSort('section');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLandingSettings::route('/'),
            'create' => Pages\CreateLandingSetting::route('/create'),
            'edit' => Pages\EditLandingSetting::route('/{record}/edit'),
        ];
    }
}
