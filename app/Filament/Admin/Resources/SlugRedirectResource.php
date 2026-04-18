<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\Cms\Models\SlugRedirect;
use App\Filament\Admin\Resources\SlugRedirectResource\Pages;
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
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SlugRedirectResource extends Resource
{
    protected static ?string $model = SlugRedirect::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowTopRightOnSquare;

    protected static string|\UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $recordTitleAttribute = 'old_slug';

    protected static ?int $navigationSort = 180;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Redirect')->columns(2)->schema([
                Forms\Components\TextInput::make('old_slug')
                    ->required()
                    ->maxLength(255)
                    ->helperText('The deprecated URL slug (without leading /).'),
                Forms\Components\TextInput::make('new_slug')
                    ->required()
                    ->maxLength(255)
                    ->helperText('Where to redirect visitors.'),
                Forms\Components\Select::make('type')
                    ->options([
                        'page' => 'CMS Page',
                        'post' => 'Blog Post',
                        'category' => 'Blog Category',
                        'tag' => 'Blog Tag',
                        'other' => 'Other',
                    ])
                    ->default('page')
                    ->native(false)
                    ->required(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('type')->badge()->color('gray'),
                TextColumn::make('old_slug')->label('From')->searchable()->copyable(),
                TextColumn::make('new_slug')->label('To')->searchable()->copyable(),
                TextColumn::make('created_at')->label('Added')->since()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('type')->options([
                    'page' => 'CMS Page',
                    'post' => 'Blog Post',
                    'category' => 'Blog Category',
                    'tag' => 'Blog Tag',
                    'other' => 'Other',
                ]),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSlugRedirects::route('/'),
            'create' => Pages\CreateSlugRedirect::route('/create'),
            'edit' => Pages\EditSlugRedirect::route('/{record}/edit'),
        ];
    }
}
