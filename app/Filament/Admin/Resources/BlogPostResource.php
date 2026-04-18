<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\Blog\Models\BlogCategory;
use App\Domain\Blog\Models\BlogPost;
use App\Domain\Blog\Models\BlogTag;
use App\Filament\Admin\Resources\BlogPostResource\Pages;
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
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class BlogPostResource extends Resource
{
    protected static ?string $model = BlogPost::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNewspaper;

    protected static string|\UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $recordTitleAttribute = 'title_en';

    protected static ?int $navigationSort = 120;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Meta')->columns(2)->schema([
                Forms\Components\TextInput::make('slug')->required()->unique(ignoreRecord: true)->maxLength(255),
                Forms\Components\Select::make('category_id')
                    ->label('Category')
                    ->options(fn (): array => BlogCategory::query()->pluck('name_en', 'id')->all())
                    ->searchable()
                    ->preload(),
                Forms\Components\TextInput::make('author_name')->maxLength(255),
                Forms\Components\TextInput::make('reading_time')->numeric()->minValue(0)->suffix('min'),
                Forms\Components\FileUpload::make('cover_image')->image()->columnSpanFull()->directory('blog'),
                Forms\Components\Select::make('tags')
                    ->multiple()
                    ->relationship('tags', 'name_en')
                    ->options(fn (): array => BlogTag::query()->pluck('name_en', 'id')->all())
                    ->preload()
                    ->columnSpanFull(),
                Forms\Components\Toggle::make('is_published'),
                Forms\Components\Toggle::make('is_featured'),
                Forms\Components\DateTimePicker::make('published_at')->columnSpanFull(),
            ]),
            Section::make('English')->columns(1)->schema([
                Forms\Components\TextInput::make('title_en')->label('Title')->required()->maxLength(255),
                Forms\Components\Textarea::make('excerpt_en')->label('Excerpt')->rows(2),
                Forms\Components\Textarea::make('meta_description_en')->label('Meta description')->rows(2),
                Forms\Components\Textarea::make('content_en')->label('Content')->rows(12)->required(),
            ]),
            Section::make('Arabic')->columns(1)->schema([
                Forms\Components\TextInput::make('title_ar')->label('Title')->required()->maxLength(255)->extraInputAttributes(['dir' => 'rtl']),
                Forms\Components\Textarea::make('excerpt_ar')->label('Excerpt')->rows(2)->extraInputAttributes(['dir' => 'rtl']),
                Forms\Components\Textarea::make('meta_description_ar')->label('Meta description')->rows(2)->extraInputAttributes(['dir' => 'rtl']),
                Forms\Components\Textarea::make('content_ar')->label('Content')->rows(12)->required()->extraInputAttributes(['dir' => 'rtl']),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title_en')->label('Title')->searchable()->limit(60)->sortable(),
                TextColumn::make('category.name_en')->label('Category')->toggleable(),
                TextColumn::make('author_name')->label('Author')->toggleable(),
                IconColumn::make('is_published')->label('Published')->boolean(),
                IconColumn::make('is_featured')->label('Featured')->boolean()->toggleable(),
                TextColumn::make('views_count')->label('Views')->badge()->toggleable(),
                TextColumn::make('published_at')->dateTime('Y-m-d')->sortable()->toggleable(),
                TextColumn::make('updated_at')->label('Updated')->since()->toggleable(),
            ])
            ->filters([
                TernaryFilter::make('is_published'),
                TernaryFilter::make('is_featured'),
                SelectFilter::make('category_id')
                    ->label('Category')
                    ->options(fn (): array => BlogCategory::query()->pluck('name_en', 'id')->all()),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])])
            ->defaultSort('published_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBlogPosts::route('/'),
            'create' => Pages\CreateBlogPost::route('/create'),
            'edit' => Pages\EditBlogPost::route('/{record}/edit'),
        ];
    }
}
