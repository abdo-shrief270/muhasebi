<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\Blog\Models\BlogTag;
use App\Filament\Admin\Resources\BlogTagResource\Pages;
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

class BlogTagResource extends Resource
{
    protected static ?string $model = BlogTag::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|\UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $recordTitleAttribute = 'name_en';

    protected static ?int $navigationSort = 140;

    public static function getModelLabel(): string
    {
        return (string) __('admin.resources.blog_tag.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return (string) __('admin.resources.blog_tag.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Tag')->columns(2)->schema([
                Forms\Components\TextInput::make('slug')->required()->unique(ignoreRecord: true)->maxLength(255),
                Forms\Components\TextInput::make('name_en')->label('Name (EN)')->required()->maxLength(255),
                Forms\Components\TextInput::make('name_ar')->label('Name (AR)')->required()->maxLength(255)->extraInputAttributes(['dir' => 'rtl']),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name_en')->label('Name')->searchable()->sortable(),
                TextColumn::make('slug')->badge()->color('gray')->searchable(),
                TextColumn::make('posts_count')->label('Posts')->counts('posts')->badge(),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])])
            ->defaultSort('name_en');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBlogTags::route('/'),
            'create' => Pages\CreateBlogTag::route('/create'),
            'edit' => Pages\EditBlogTag::route('/{record}/edit'),
        ];
    }
}
