<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\Subscription\Enums\DiscountType;
use App\Domain\Subscription\Models\Coupon;
use App\Domain\Subscription\Models\Plan;
use App\Filament\Admin\Resources\CouponResource\Pages;
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

class CouponResource extends Resource
{
    protected static ?string $model = Coupon::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static string|\UnitEnum|null $navigationGroup = 'Billing';

    protected static ?string $recordTitleAttribute = 'code';

    protected static ?int $navigationSort = 70;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Coupon')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('code')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(50)
                        ->extraInputAttributes(['style' => 'text-transform:uppercase']),
                    Forms\Components\Toggle::make('is_active')->default(true),
                    Forms\Components\TextInput::make('description')->maxLength(255)->columnSpanFull(),
                ]),

            Section::make('Discount')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('discount_type')
                        ->options(collect(DiscountType::cases())->mapWithKeys(
                            fn (DiscountType $t) => [$t->value => $t->label()]
                        )->all())
                        ->default(DiscountType::Percent->value)
                        ->native(false)
                        ->required(),
                    Forms\Components\TextInput::make('discount_value')
                        ->numeric()
                        ->minValue(0)
                        ->required()
                        ->helperText('Percent: 0–100. Fixed: absolute amount in currency.'),
                    Forms\Components\TextInput::make('currency')
                        ->default('EGP')
                        ->length(3)
                        ->required(),
                ]),

            Section::make('Limits')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('max_uses')
                        ->numeric()
                        ->minValue(1)
                        ->helperText('Leave empty for unlimited uses.'),
                    Forms\Components\DateTimePicker::make('expires_at')
                        ->helperText('Leave empty for no expiry.'),
                    Forms\Components\Select::make('applies_to_plan_ids')
                        ->label('Applies to plans')
                        ->multiple()
                        ->options(fn (): array => Plan::query()->pluck('name_en', 'id')->all())
                        ->helperText('Leave empty to allow all plans.')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->badge()->color('primary')->searchable()->copyable(),
                TextColumn::make('discount_type')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof DiscountType ? $state->label() : (string) $state),
                TextColumn::make('discount_value')
                    ->label('Value')
                    ->formatStateUsing(fn ($state, Coupon $record): string => $record->discount_type === DiscountType::Percent
                        ? number_format((float) $state, 2).'%'
                        : number_format((float) $state, 2).' '.$record->currency),
                TextColumn::make('used_count')
                    ->label('Used')
                    ->formatStateUsing(fn ($state, Coupon $record): string => $record->max_uses
                        ? "{$state} / {$record->max_uses}"
                        : (string) $state)
                    ->badge(),
                TextColumn::make('expires_at')->label('Expires')->dateTime('Y-m-d')->toggleable(),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Active'),
                SelectFilter::make('discount_type')->options(
                    collect(DiscountType::cases())->mapWithKeys(fn (DiscountType $t) => [$t->value => $t->label()])->all()
                ),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])])
            ->defaultSort('code');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCoupons::route('/'),
            'create' => Pages\CreateCoupon::route('/create'),
            'edit' => Pages\EditCoupon::route('/{record}/edit'),
        ];
    }
}
