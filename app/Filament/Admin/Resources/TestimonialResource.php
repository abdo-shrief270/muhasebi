<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\Cms\Models\Testimonial;
use App\Filament\Admin\Resources\TestimonialResource\Pages;
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

class TestimonialResource extends Resource
{
    protected static ?string $model = Testimonial::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleBottomCenterText;

    protected static string|\UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $recordTitleAttribute = 'name_en';

    protected static ?int $navigationSort = 160;

    public static function getModelLabel(): string
    {
        return (string) __('admin.resources.testimonial.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return (string) __('admin.resources.testimonial.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Testimonial')->columns(2)->schema([
                Forms\Components\TextInput::make('name_en')->label('Name (EN)')->required()->maxLength(255),
                Forms\Components\TextInput::make('name_ar')->label('Name (AR)')->required()->maxLength(255)->extraInputAttributes(['dir' => 'rtl']),
                Forms\Components\TextInput::make('role_en')->label('Role (EN)')->maxLength(255),
                Forms\Components\TextInput::make('role_ar')->label('Role (AR)')->maxLength(255)->extraInputAttributes(['dir' => 'rtl']),
                Forms\Components\Textarea::make('quote_en')->label('Quote (EN)')->required()->rows(4),
                Forms\Components\Textarea::make('quote_ar')->label('Quote (AR)')->required()->rows(4)->extraInputAttributes(['dir' => 'rtl']),
                Forms\Components\TextInput::make('rating')->numeric()->minValue(1)->maxValue(5)->default(5),
                Forms\Components\TextInput::make('sort_order')->numeric()->default(0),
                Forms\Components\Toggle::make('is_active')->default(true),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sort_order')->label('#')->sortable(),
                TextColumn::make('name_en')->label('Name')->searchable()->sortable(),
                TextColumn::make('role_en')->label('Role')->toggleable(),
                TextColumn::make('rating')->badge()->color('warning')->formatStateUsing(fn ($state) => str_repeat('★', (int) $state)),
                TextColumn::make('quote_en')->label('Quote')->limit(60),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->filters([TernaryFilter::make('is_active')])
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])])
            ->defaultSort('sort_order');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTestimonials::route('/'),
            'create' => Pages\CreateTestimonial::route('/create'),
            'edit' => Pages\EditTestimonial::route('/{record}/edit'),
        ];
    }
}
