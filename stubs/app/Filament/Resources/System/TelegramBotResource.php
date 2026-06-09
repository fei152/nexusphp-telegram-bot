<?php

namespace App\Filament\Resources\System;

use App\Filament\Resources\System\TelegramBotResource\Pages\ManageTelegramBots;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
use NexusPlugin\TelegramBot\Models\Bot;
use NexusPlugin\TelegramBot\Services\TelegramBotService;

class TelegramBotResource extends Resource
{
    protected static ?string $model = Bot::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static string | \UnitEnum | null $navigationGroup = 'System';

    protected static ?int $navigationSort = 16;

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
                    TextInput::make('name')
                        ->label(nexus_trans('telegram-bot::messages.fields.name'))
                        ->required()
                        ->maxLength(100),
                    TextInput::make('token')
                        ->label(nexus_trans('telegram-bot::messages.fields.token'))
                        ->password()
                        ->revealable()
                        ->required(fn ($record) => !$record?->exists)
                        ->dehydrated(fn ($state) => filled($state))
                        ->maxLength(200),
                    TextInput::make('username')
                        ->label(nexus_trans('telegram-bot::messages.fields.username'))
                        ->maxLength(64),
                    TextInput::make('webhook_path')
                        ->label(nexus_trans('telegram-bot::messages.fields.webhook_path'))
                        ->maxLength(100)
                        ->helperText(nexus_trans('telegram-bot::messages.fields.webhook_path_help')),
                    TextInput::make('webhook_secret')
                        ->label(nexus_trans('telegram-bot::messages.fields.webhook_secret'))
                        ->maxLength(128)
                        ->helperText(nexus_trans('telegram-bot::messages.fields.webhook_secret_help')),
                    Select::make('allowed_updates')
                        ->label(nexus_trans('telegram-bot::messages.fields.allowed_updates'))
                        ->multiple()
                        ->options([
                            'message' => 'message',
                            'edited_message' => 'edited_message',
                            'callback_query' => 'callback_query',
                        ])
                        ->default(['message']),
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
                TextColumn::make('name')
                    ->label(nexus_trans('telegram-bot::messages.fields.name'))
                    ->searchable(),
                TextColumn::make('username')
                    ->label(nexus_trans('telegram-bot::messages.fields.username'))
                    ->searchable(),
                TextColumn::make('webhook_url')
                    ->label(nexus_trans('telegram-bot::messages.fields.webhook_url'))
                    ->state(fn (Bot $record) => $record->webhook_url),
                IconColumn::make('is_enabled')
                    ->label(nexus_trans('telegram-bot::messages.fields.is_enabled'))
                    ->boolean(),
                TextColumn::make('last_webhook_set_at')
                    ->label(nexus_trans('telegram-bot::messages.fields.last_webhook_set_at')),
                TextColumn::make('last_error')
                    ->label(nexus_trans('telegram-bot::messages.fields.last_error'))
                    ->limit(40),
            ])
            ->defaultSort('id', 'desc')
            ->headerActions([
                self::announcementSettingsAction(),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('refresh_bot')
                    ->label(nexus_trans('telegram-bot::messages.actions.refresh_bot'))
                    ->icon('heroicon-o-arrow-path')
                    ->action(function (Bot $record): void {
                        app(TelegramBotService::class)->refreshBotUsername($record);
                        Notification::make()->success()->title(nexus_trans('telegram-bot::messages.messages.bot_refreshed'))->send();
                    }),
                Action::make('set_webhook')
                    ->label(nexus_trans('telegram-bot::messages.actions.set_webhook'))
                    ->icon('heroicon-o-cloud-arrow-up')
                    ->requiresConfirmation()
                    ->action(function (Bot $record): void {
                        app(TelegramBotService::class)->setWebhook($record);
                        Notification::make()->success()->title(nexus_trans('telegram-bot::messages.messages.webhook_set'))->send();
                    }),
                Action::make('delete_webhook')
                    ->label(nexus_trans('telegram-bot::messages.actions.delete_webhook'))
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (Bot $record): void {
                        app(TelegramBotService::class)->deleteWebhook($record);
                        Notification::make()->success()->title(nexus_trans('telegram-bot::messages.messages.webhook_deleted'))->send();
                    }),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                DeleteBulkAction::make(),
            ]);
    }

    private static function announcementSettingsAction(): Action
    {
        return Action::make('announcement_settings')
            ->label(nexus_trans('telegram-bot::messages.actions.announcement_settings'))
            ->icon('heroicon-o-megaphone')
            ->modalHeading(nexus_trans('telegram-bot::messages.actions.announcement_settings'))
            ->schema([
                Section::make(nexus_trans('telegram-bot::messages.fields.announcement_sync'))
                    ->schema([
                        Toggle::make('announcement_sync_group_pin')
                            ->label(nexus_trans('telegram-bot::messages.fields.announcement_sync_group_pin'))
                            ->helperText(nexus_trans('telegram-bot::messages.fields.announcement_sync_group_pin_help')),
                        TextInput::make('announcement_group_chat_id')
                            ->label(nexus_trans('telegram-bot::messages.fields.announcement_group_chat_id'))
                            ->helperText(nexus_trans('telegram-bot::messages.fields.announcement_group_chat_id_help'))
                            ->maxLength(100),
                        Toggle::make('announcement_sync_channel')
                            ->label(nexus_trans('telegram-bot::messages.fields.announcement_sync_channel'))
                            ->helperText(nexus_trans('telegram-bot::messages.fields.announcement_sync_channel_help')),
                        TextInput::make('announcement_channel_chat_id')
                            ->label(nexus_trans('telegram-bot::messages.fields.announcement_channel_chat_id'))
                            ->helperText(nexus_trans('telegram-bot::messages.fields.announcement_channel_chat_id_help'))
                            ->maxLength(100),
                    ])
                    ->columns(2),
            ])
            ->fillForm(fn () => app(TelegramBotService::class)->announcementSettings())
            ->action(function (array $data): void {
                app(TelegramBotService::class)->saveAnnouncementSettings($data);
                Notification::make()->success()->title(nexus_trans('telegram-bot::messages.messages.announcement_settings_saved'))->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageTelegramBots::route('/'),
        ];
    }
}
