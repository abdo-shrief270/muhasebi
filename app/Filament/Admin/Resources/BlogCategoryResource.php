<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\Blog\Models\BlogCategory;
use App\Filament\Admin\Resources\BlogCategoryResource\Pages;
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

class BlogCategoryResource extends Resource
{
    protected static ?string $model = BlogCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $recordTitleAttribute = 'name_en';

    protected static ?int $navigationSort = 130;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Category')->columns(2)->schema([
                Forms\Components\TextInput::make('slug')->required()->unique(ignoreRecord: true)->maxLength(255),
                Forms\Components\TextInput::make('sort_order')->numeric()->default(0),
                Forms\Components\TextInput::make('name_en')->label('Name (EN)')->required()->maxLength(255),
                Forms\Components\TextInput::make('name_ar')->label('Name (AR)')->required()->maxLength(255)->extraInputAttributes(['dir' => 'rtl']),
                Forms\Components\Textarea::make('description_en')->label('Description (EN)')->rows(2),
                Forms\Components\Textarea::make('description_ar')->label('Description (AR)')->rows(2)->extraInputAttributes(['dir' => 'rtl']),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sort_order')->label('#')->sortable(),
                TextColumn::make('name_en')->label('Name')->searchable()->sortable(),
                TextColumn::make('slug')->badge()->color('gray')->searchable(),
                TextColumn::make('posts_count')->label('Posts')->counts('posts')->badge(),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])])
            ->defaultSort('sort_order');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBlogCategories::route('/'),
            'create' => Pages\CreateBlogCategory::route('/create'),
            'edit' => Pages\EditBlogCategory::route('/{record}/edit'),
        ];
    }
}
