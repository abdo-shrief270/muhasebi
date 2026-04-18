<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\Cms\Models\Faq;
use App\Filament\Admin\Resources\FaqResource\Pages;
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

class FaqResource extends Resource
{
    protected static ?string $model = Faq::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQuestionMarkCircle;

    protected static string|\UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $recordTitleAttribute = 'question_en';

    protected static ?int $navigationSort = 150;

    protected static ?string $pluralModelLabel = 'FAQs';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Q&A')->columns(2)->schema([
                Forms\Components\TextInput::make('sort_order')->numeric()->default(0),
                Forms\Components\Toggle::make('is_active')->default(true),
                Forms\Components\TextInput::make('question_en')->label('Question (EN)')->required()->maxLength(500)->columnSpanFull(),
                Forms\Components\TextInput::make('question_ar')->label('Question (AR)')->required()->maxLength(500)->extraInputAttributes(['dir' => 'rtl'])->columnSpanFull(),
                Forms\Components\Textarea::make('answer_en')->label('Answer (EN)')->required()->rows(4)->columnSpanFull(),
                Forms\Components\Textarea::make('answer_ar')->label('Answer (AR)')->required()->rows(4)->extraInputAttributes(['dir' => 'rtl'])->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sort_order')->label('#')->sortable(),
                TextColumn::make('question_en')->label('Question')->searchable()->limit(70),
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
            'index' => Pages\ListFaqs::route('/'),
            'create' => Pages\CreateFaq::route('/create'),
            'edit' => Pages\EditFaq::route('/{record}/edit'),
        ];
    }
}
