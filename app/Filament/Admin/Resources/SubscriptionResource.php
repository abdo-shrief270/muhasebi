<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\Subscription\Enums\SubscriptionStatus;
use App\Domain\Subscription\Models\Plan;
use App\Domain\Subscription\Models\Subscription;
use App\Filament\Admin\Resources\SubscriptionResource\Pages;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SubscriptionResource extends Resource
{
    protected static ?string $model = Subscription::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string | \UnitEnum | null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Subscription')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('tenant_id')
                        ->label('Tenant')
                        ->relationship('tenant', 'name')
                        ->searchable()
                        ->preload()
                        ->required(),
                    Forms\Components\Select::make('plan_id')
                        ->label('Plan')
                        ->relationship('plan', 'name_en')
                        ->searchable()
                        ->preload()
                        ->required(),
                    Forms\Components\Select::make('status')
                        ->label('Status')
                        ->options(collect(SubscriptionStatus::cases())->mapWithKeys(
                            fn (SubscriptionStatus $s) => [$s->value => $s->label()]
                        )->all())
                        ->required()
                        ->native(false),
                    Forms\Components\Select::make('billing_cycle')
                        ->label('Billing Cycle')
                        ->options([
                            'monthly' => 'Monthly',
                            'annual' => 'Annual',
                        ])
                        ->default('monthly')
                        ->required()
                        ->native(false),
                    Forms\Components\TextInput::make('price')
                        ->label('Price')
                        ->numeric()
                        ->prefix('EGP')
                        ->minValue(0),
                    Forms\Components\TextInput::make('currency')
                        ->label('Currency')
                        ->default('EGP')
                        ->maxLength(3),
                ]),

            Section::make('Dates')
                ->columns(3)
                ->schema([
                    Forms\Components\DateTimePicker::make('trial_ends_at')
                        ->label('Trial Ends At'),
                    Forms\Components\DatePicker::make('current_period_start')
                        ->label('Period Start'),
                    Forms\Components\DatePicker::make('current_period_end')
                        ->label('Period End'),
                    Forms\Components\DateTimePicker::make('cancelled_at')
                        ->label('Cancelled At'),
                    Forms\Components\DateTimePicker::make('expires_at')
                        ->label('Expires At'),
                    Forms\Components\Textarea::make('cancellation_reason')
                        ->label('Cancellation Reason')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withoutGlobalScope('tenant'))
            ->columns([
                TextColumn::make('tenant.name')
                    ->label('Tenant')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('plan.name_en')
                    ->label('Plan')
                    ->badge()
                    ->color('info')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof SubscriptionStatus ? $state->label() : (string) $state)
                    ->color(fn ($state): string => match ($state instanceof SubscriptionStatus ? $state->value : $state) {
                        'active' => 'success',
                        'trial' => 'info',
                        'past_due' => 'warning',
                        'cancelled' => 'danger',
                        'expired' => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('billing_cycle')
                    ->label('Cycle')
                    ->badge()
                    ->color('gray')
                    ->toggleable(),
                TextColumn::make('price')
                    ->label('Price')
                    ->money('EGP')
                    ->sortable(),
                TextColumn::make('current_period_start')
                    ->label('Starts')
                    ->date('Y-m-d')
                    ->sortable(),
                TextColumn::make('current_period_end')
                    ->label('Ends')
                    ->date('Y-m-d')
                    ->sortable(),
                TextColumn::make('trial_ends_at')
                    ->label('Trial Ends')
                    ->dateTime('Y-m-d')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(SubscriptionStatus::cases())->mapWithKeys(
                        fn (SubscriptionStatus $s) => [$s->value => $s->label()]
                    )->all()),
                SelectFilter::make('plan_id')
                    ->label('Plan')
                    ->options(fn (): array => Plan::query()->pluck('name_en', 'id')->all()),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSubscriptions::route('/'),
            'create' => Pages\CreateSubscription::route('/create'),
            'view' => Pages\ViewSubscription::route('/{record}'),
            'edit' => Pages\EditSubscription::route('/{record}/edit'),
        ];
    }
}
