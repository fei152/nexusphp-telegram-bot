<?php

namespace App\Filament\Resources\System;

use App\Filament\Resources\System\TelegramBotBindResource\Pages\ManageTelegramBotBinds;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use NexusPlugin\TelegramBot\Models\Bind;
use NexusPlugin\TelegramBot\Models\Bot;
use NexusPlugin\TelegramBot\Services\TelegramBotService;

class TelegramBotBindResource extends Resource
{
    protected static ?string $model = Bind::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-link';

    protected static string | \UnitEnum | null $navigationGroup = 'System';

    protected static ?int $navigationSort = 17;

    protected static bool $shouldRegisterNavigation = false;

    public static function getNavigationLabel(): string
    {
        return nexus_trans('telegram-bot::messages.admin.nav');
    }

    public static function getBreadcrumb(): string
    {
        return self::getNavigationLabel();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(self::getNavigationLabel())
                ->schema([
                    Select::make('bot_id')
                        ->label(nexus_trans('telegram-bot::messages.fields.bot'))
                        ->options(fn () => Bot::query()->orderBy('name')->pluck('name', 'id'))
                        ->required(),
                    TextInput::make('uid')
                        ->label(nexus_trans('telegram-bot::messages.fields.uid'))
                        ->integer()
                        ->required(),
                    TextInput::make('telegram_id')
                        ->label(nexus_trans('telegram-bot::messages.fields.telegram_id'))
                        ->maxLength(64),
                    TextInput::make('telegram_username')
                        ->label(nexus_trans('telegram-bot::messages.fields.telegram_username'))
                        ->maxLength(128),
                    TextInput::make('bind_token')
                        ->label(nexus_trans('telegram-bot::messages.fields.bind_token'))
                        ->maxLength(80),
                    Toggle::make('is_enabled')
                        ->label(nexus_trans('telegram-bot::messages.fields.is_enabled'))
                        ->default(true),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id'),
                TextColumn::make('bot.name')
                    ->label(nexus_trans('telegram-bot::messages.fields.bot')),
                TextColumn::make('uid')
                    ->label(nexus_trans('telegram-bot::messages.fields.user'))
                    ->formatStateUsing(fn (Bind $record) => username_for_admin($record->uid))
                    ->searchable(),
                TextColumn::make('telegram_id')
                    ->label(nexus_trans('telegram-bot::messages.fields.telegram_id'))
                    ->searchable(),
                TextColumn::make('display_name')
                    ->label(nexus_trans('telegram-bot::messages.fields.telegram_name')),
                TextColumn::make('bind_token')
                    ->label(nexus_trans('telegram-bot::messages.fields.bind_token'))
                    ->limit(24),
                TextColumn::make('bound_at')
                    ->label(nexus_trans('telegram-bot::messages.fields.bound_at')),
                TextColumn::make('last_seen_at')
                    ->label(nexus_trans('telegram-bot::messages.fields.last_seen_at')),
                IconColumn::make('is_enabled')
                    ->label(nexus_trans('telegram-bot::messages.fields.is_enabled'))
                    ->boolean(),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                EditAction::make(),
                Action::make('regenerate_token')
                    ->label(nexus_trans('telegram-bot::messages.actions.regenerate_token'))
                    ->icon('heroicon-o-key')
                    ->action(function (Bind $record): void {
                        $record->update(['bind_token' => Bind::newToken()]);
                        Notification::make()->success()->title(nexus_trans('telegram-bot::messages.messages.token_regenerated'))->send();
                    }),
                Action::make('send_test')
                    ->label(nexus_trans('telegram-bot::messages.actions.send_test'))
                    ->icon('heroicon-o-paper-airplane')
                    ->hidden(fn (Bind $record) => !$record->telegram_id)
                    ->schema([
                        Textarea::make('message')
                            ->label(nexus_trans('telegram-bot::messages.fields.test_message'))
                            ->default(nexus_trans('telegram-bot::messages.fields.test_message_default'))
                            ->required(),
                    ])
                    ->action(function (Bind $record, array $data): void {
                        app(TelegramBotService::class)->sendMessage($record->bot, $record->telegram_id, $data['message']);
                        Notification::make()->success()->title(nexus_trans('telegram-bot::messages.messages.test_sent'))->send();
                    }),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageTelegramBotBinds::route('/'),
        ];
    }
}
