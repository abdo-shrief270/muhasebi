<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\Cms\Models\ContactSubmission;
use App\Filament\Admin\Resources\ContactSubmissionResource\Pages;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ContactSubmissionResource extends Resource
{
    protected static ?string $model = ContactSubmission::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInbox;

    protected static string|\UnitEnum|null $navigationGroup = 'Platform';

    protected static ?string $recordTitleAttribute = 'subject';

    protected static ?int $navigationSort = 100;

    public static function getNavigationBadge(): ?string
    {
        $unread = static::getEloquentQuery()->where('is_read', false)->count();

        return $unread > 0 ? (string) $unread : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Submission')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')->disabled(),
                    Forms\Components\TextInput::make('email')->disabled(),
                    Forms\Components\TextInput::make('phone')->disabled(),
                    Forms\Components\TextInput::make('company')->disabled(),
                    Forms\Components\TextInput::make('subject')->disabled()->columnSpanFull(),
                    Forms\Components\Textarea::make('message')->disabled()->rows(6)->columnSpanFull(),
                ]),

            Section::make('Admin')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('status')
                        ->options([
                            'new' => 'New',
                            'in_progress' => 'In progress',
                            'resolved' => 'Resolved',
                            'spam' => 'Spam',
                        ])
                        ->default('new')
                        ->native(false),
                    Forms\Components\Select::make('assigned_to')
                        ->label('Assigned to')
                        ->options(fn (): array => User::query()
                            ->withoutGlobalScope('tenant')
                            ->where('is_active', true)
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->nullable(),
                    Forms\Components\Toggle::make('is_read')->label('Read'),
                    Forms\Components\Textarea::make('admin_notes')->label('Admin notes')->rows(4)->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable()->copyable()->toggleable(),
                TextColumn::make('subject')->searchable()->limit(50)->wrap(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn ($state): string => match ($state) {
                        'new' => 'warning',
                        'in_progress' => 'info',
                        'resolved' => 'success',
                        'spam' => 'danger',
                        default => 'gray',
                    }),
                IconColumn::make('is_read')->label('Read')->boolean(),
                TextColumn::make('assignee.name')->label('Assigned')->toggleable(),
                TextColumn::make('created_at')->label('Received')->since()->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_read')->label('Read'),
                SelectFilter::make('status')->options([
                    'new' => 'New',
                    'in_progress' => 'In progress',
                    'resolved' => 'Resolved',
                    'spam' => 'Spam',
                ]),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('mark_read')
                    ->label('Mark read')
                    ->icon(Heroicon::OutlinedCheck)
                    ->color('primary')
                    ->visible(fn (ContactSubmission $record): bool => ! $record->is_read)
                    ->action(function (ContactSubmission $record): void {
                        $record->forceFill(['is_read' => true])->save();
                        Notification::make()->title('Marked as read')->success()->send();
                    }),
                Action::make('resolve')
                    ->label('Resolve')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (ContactSubmission $record): bool => $record->status !== 'resolved')
                    ->requiresConfirmation()
                    ->action(function (ContactSubmission $record): void {
                        $record->forceFill(['status' => 'resolved', 'is_read' => true])->save();
                        Notification::make()->title('Marked resolved')->success()->send();
                    }),
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
            'index' => Pages\ListContactSubmissions::route('/'),
            'view' => Pages\ViewContactSubmission::route('/{record}'),
            'edit' => Pages\EditContactSubmission::route('/{record}/edit'),
        ];
    }
}
