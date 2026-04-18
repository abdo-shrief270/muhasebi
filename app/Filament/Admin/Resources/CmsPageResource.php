<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\Cms\Models\CmsPage;
use App\Filament\Admin\Resources\CmsPageResource\Pages;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class CmsPageResource extends Resource
{
    protected static ?string $model = CmsPage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|\UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $recordTitleAttribute = 'slug';

    protected static ?int $navigationSort = 110;

    public static function getModelLabel(): string
    {
        return (string) __('admin.resources.cms_page.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return (string) __('admin.resources.cms_page.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Page')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('slug')->required()->unique(ignoreRecord: true)->maxLength(255),
                    Forms\Components\Toggle::make('is_published')->default(false),
                ]),
            Section::make('English')->columns(1)->schema([
                Forms\Components\TextInput::make('title_en')->label('Title')->required()->maxLength(255),
                Forms\Components\Textarea::make('meta_description_en')->label('Meta description')->rows(2),
                Forms\Components\Textarea::make('content_en')->label('Content')->rows(12)->required(),
            ]),
            Section::make('Arabic')->columns(1)->schema([
                Forms\Components\TextInput::make('title_ar')->label('Title')->required()->maxLength(255)->extraInputAttributes(['dir' => 'rtl']),
                Forms\Components\Textarea::make('meta_description_ar')->label('Meta description')->rows(2)->extraInputAttributes(['dir' => 'rtl']),
                Forms\Components\Textarea::make('content_ar')->label('Content')->rows(12)->required()->extraInputAttributes(['dir' => 'rtl']),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('slug')->badge()->color('gray')->searchable()->sortable(),
                TextColumn::make('title_en')->label('Title (EN)')->searchable()->limit(60),
                TextColumn::make('title_ar')->label('Title (AR)')->limit(60)->toggleable(),
                IconColumn::make('is_published')->label('Published')->boolean(),
                TextColumn::make('updated_at')->label('Updated')->since()->toggleable(),
            ])
            ->filters([TernaryFilter::make('is_published')])
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])])
            ->defaultSort('slug');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCmsPages::route('/'),
            'create' => Pages\CreateCmsPage::route('/create'),
            'edit' => Pages\EditCmsPage::route('/{record}/edit'),
        ];
    }
}
