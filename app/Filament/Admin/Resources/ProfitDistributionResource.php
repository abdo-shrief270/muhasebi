<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\Investor\Enums\DistributionStatus;
use App\Domain\Investor\Models\Investor;
use App\Domain\Investor\Models\ProfitDistribution;
use App\Domain\Tenant\Models\Tenant;
use App\Filament\Admin\Resources\ProfitDistributionResource\Pages;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProfitDistributionResource extends Resource
{
    protected static ?string $model = ProfitDistribution::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|\UnitEnum|null $navigationGroup = 'Investors';

    protected static ?string $recordTitleAttribute = 'id';

    protected static ?int $navigationSort = 90;

    protected static ?string $modelLabel = 'Distribution';

    protected static ?string $pluralModelLabel = 'Profit Distributions';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScope('tenant');
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = static::getEloquentQuery()
            ->whereIn('status', [DistributionStatus::Draft, DistributionStatus::Approved])
            ->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Parties')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('investor_id')
                        ->label('Investor')
                        ->options(fn (): array => Investor::query()->pluck('name', 'id')->all())
                        ->searchable()
                        ->preload()
                        ->required(),
                    Forms\Components\Select::make('tenant_id')
                        ->label('Tenant')
                        ->options(fn (): array => Tenant::query()->pluck('name', 'id')->all())
                        ->searchable()
                        ->preload()
                        ->required(),
                ]),

            Section::make('Period')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('month')
                        ->label('Month')
                        ->options(array_combine(range(1, 12), [
                            'January', 'February', 'March', 'April', 'May', 'June',
                            'July', 'August', 'September', 'October', 'November', 'December',
                        ]))
                        ->native(false)
                        ->required(),
                    Forms\Components\TextInput::make('year')
                        ->numeric()
                        ->minValue(2024)
                        ->maxValue(2100)
                        ->required()
                        ->default((int) now()->format('Y')),
                ]),

            Section::make('Financials')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('tenant_revenue')
                        ->numeric()
                        ->prefix('EGP')
                        ->minValue(0)
                        ->default(0),
                    Forms\Components\TextInput::make('tenant_expenses')
                        ->numeric()
                        ->prefix('EGP')
                        ->minValue(0)
                        ->default(0),
                    Forms\Components\TextInput::make('net_profit')
                        ->numeric()
                        ->prefix('EGP')
                        ->default(0)
                        ->helperText('Usually revenue minus expenses — override if a different basis applies.'),
                    Forms\Components\TextInput::make('ownership_percentage')
                        ->numeric()
                        ->suffix('%')
                        ->minValue(0)
                        ->maxValue(100)
                        ->required(),
                    Forms\Components\TextInput::make('investor_share')
                        ->numeric()
                        ->prefix('EGP')
                        ->default(0)
                        ->helperText('net_profit × ownership_percentage / 100.'),
                ]),

            Section::make('Status & Notes')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('status')
                        ->options(collect(DistributionStatus::cases())->mapWithKeys(
                            fn (DistributionStatus $s) => [$s->value => $s->label()]
                        )->all())
                        ->default(DistributionStatus::Draft->value)
                        ->native(false)
                        ->required(),
                    Forms\Components\DateTimePicker::make('paid_at')
                        ->label('Paid at'),
                    Forms\Components\Textarea::make('notes')
                        ->rows(3)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('investor.name')
                    ->label('Investor')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('tenant.name')
                    ->label('Tenant')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('period')
                    ->label('Period')
                    ->state(fn (ProfitDistribution $record): string => sprintf('%04d-%02d', $record->year, $record->month))
                    ->sortable(['year', 'month']),
                TextColumn::make('net_profit')
                    ->label('Net Profit')
                    ->money('EGP')
                    ->sortable(),
                TextColumn::make('ownership_percentage')
                    ->label('Own %')
                    ->suffix('%')
                    ->toggleable(),
                TextColumn::make('investor_share')
                    ->label('Investor Share')
                    ->money('EGP')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof DistributionStatus ? $state->label() : (string) $state)
                    ->color(fn ($state): string => match ($state instanceof DistributionStatus ? $state->value : $state) {
                        'draft' => 'gray',
                        'approved' => 'warning',
                        'paid' => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('paid_at')
                    ->label('Paid')
                    ->dateTime('Y-m-d')
                    ->toggleable()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(DistributionStatus::cases())->mapWithKeys(
                        fn (DistributionStatus $s) => [$s->value => $s->label()]
                    )->all()),
                SelectFilter::make('investor_id')
                    ->label('Investor')
                    ->options(fn (): array => Investor::query()->pluck('name', 'id')->all())
                    ->searchable(),
                SelectFilter::make('tenant_id')
                    ->label('Tenant')
                    ->options(fn (): array => Tenant::query()->pluck('name', 'id')->all())
                    ->searchable(),
                SelectFilter::make('year')
                    ->options(fn (): array => ProfitDistribution::query()
                        ->distinct()
                        ->orderByDesc('year')
                        ->pluck('year', 'year')
                        ->all()),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('approve')
                    ->label('Approve')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('warning')
                    ->visible(fn (ProfitDistribution $record): bool => $record->status === DistributionStatus::Draft)
                    ->requiresConfirmation()
                    ->action(function (ProfitDistribution $record): void {
                        $record->forceFill(['status' => DistributionStatus::Approved])->save();

                        Notification::make()->title('Distribution approved')->success()->send();
                    }),
                Action::make('mark_paid')
                    ->label('Mark paid')
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->color('success')
                    ->visible(fn (ProfitDistribution $record): bool => $record->status === DistributionStatus::Approved)
                    ->requiresConfirmation()
                    ->action(function (ProfitDistribution $record): void {
                        $record->forceFill([
                            'status' => DistributionStatus::Paid,
                            'paid_at' => now(),
                        ])->save();

                        Notification::make()->title('Distribution marked paid')->success()->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProfitDistributions::route('/'),
            'create' => Pages\CreateProfitDistribution::route('/create'),
            'view' => Pages\ViewProfitDistribution::route('/{record}'),
            'edit' => Pages\EditProfitDistribution::route('/{record}/edit'),
        ];
    }
}
