<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\Shared\Enums\TenantStatus;
use App\Domain\Subscription\Models\Plan;
use App\Domain\Tenant\Models\Tenant;
use App\Filament\Admin\Resources\TenantResource\Pages;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TenantResource extends Resource
{
    protected static ?string $model = Tenant::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string | \UnitEnum | null $navigationGroup = 'Tenancy';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identity')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Name')
                        ->required()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('slug')
                        ->label('Slug')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(255),
                    Forms\Components\TextInput::make('email')
                        ->label('Email')
                        ->email()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('phone')
                        ->label('Phone')
                        ->tel()
                        ->maxLength(20),
                    Forms\Components\TextInput::make('domain')
                        ->label('Domain')
                        ->maxLength(255),
                ]),

            Section::make('Egyptian Legal')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('tax_id')
                        ->label('Tax ID (الرقم الضريبي)')
                        ->maxLength(20),
                    Forms\Components\TextInput::make('commercial_register')
                        ->label('Commercial Register (السجل التجاري)')
                        ->maxLength(30),
                    Forms\Components\TextInput::make('city')
                        ->label('City')
                        ->maxLength(100),
                    Forms\Components\Textarea::make('address')
                        ->label('Address')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),

            Section::make('Status')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('status')
                        ->label('Status')
                        ->options(collect(TenantStatus::cases())->mapWithKeys(
                            fn (TenantStatus $s) => [$s->value => $s->label()]
                        )->all())
                        ->required()
                        ->native(false),
                    Forms\Components\DateTimePicker::make('trial_ends_at')
                        ->label('Trial Ends At'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->label('Slug')
                    ->toggleable()
                    ->searchable(),
                TextColumn::make('owner_email')
                    ->label('Owner Email')
                    ->state(fn (Tenant $record): ?string => $record->users()
                        ->where('role', 'admin')
                        ->value('email')
                        ?? $record->users()->value('email')
                        ?? $record->email)
                    ->searchable(query: function ($query, string $search) {
                        return $query->orWhere('email', 'like', "%{$search}%");
                    }),
                TextColumn::make('plan')
                    ->label('Plan')
                    ->state(function (Tenant $record): ?string {
                        $sub = \App\Domain\Subscription\Models\Subscription::query()
                            ->withoutGlobalScope('tenant')
                            ->where('tenant_id', $record->id)
                            ->latest('id')
                            ->first();

                        return $sub?->plan?->name_en;
                    })
                    ->badge()
                    ->color('info'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof TenantStatus ? $state->label() : (string) $state)
                    ->color(fn ($state): string => match ($state instanceof TenantStatus ? $state->value : $state) {
                        'active' => 'success',
                        'trial' => 'info',
                        'suspended' => 'warning',
                        'cancelled' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('trial_ends_at')
                    ->label('Trial Ends')
                    ->dateTime('Y-m-d')
                    ->toggleable()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(TenantStatus::cases())->mapWithKeys(
                        fn (TenantStatus $s) => [$s->value => $s->label()]
                    )->all()),
                SelectFilter::make('plan')
                    ->label('Plan')
                    ->options(fn (): array => Plan::query()->pluck('name_en', 'id')->all())
                    ->query(function ($query, array $data) {
                        if (empty($data['value'])) {
                            return $query;
                        }

                        return $query->whereIn('id', function ($sub) use ($data) {
                            $sub->from('subscriptions')
                                ->select('tenant_id')
                                ->where('plan_id', $data['value']);
                        });
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('suspend')
                    ->label('Suspend')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('warning')
                    ->visible(fn (Tenant $record): bool => $record->status !== TenantStatus::Suspended)
                    ->requiresConfirmation()
                    ->action(fn (Tenant $record) => $record->update(['status' => TenantStatus::Suspended])),
                Action::make('activate')
                    ->label('Activate')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (Tenant $record): bool => $record->status !== TenantStatus::Active)
                    ->requiresConfirmation()
                    ->action(fn (Tenant $record) => $record->update(['status' => TenantStatus::Active])),
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
            'index' => Pages\ListTenants::route('/'),
            'create' => Pages\CreateTenant::route('/create'),
            'view' => Pages\ViewTenant::route('/{record}'),
            'edit' => Pages\EditTenant::route('/{record}/edit'),
        ];
    }
}
