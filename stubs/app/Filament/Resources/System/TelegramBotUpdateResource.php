<?php

namespace App\Filament\Resources\System;

use App\Filament\Resources\System\TelegramBotUpdateResource\Pages\ManageTelegramBotUpdates;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use NexusPlugin\TelegramBot\Models\UpdateLog;

class TelegramBotUpdateResource extends Resource
{
    protected static ?string $model = UpdateLog::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-document-text';

    protected static string | \UnitEnum | null $navigationGroup = 'System';

    protected static ?int $navigationSort = 18;

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
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id'),
                TextColumn::make('bot.name')
                    ->label(nexus_trans('telegram-bot::messages.fields.bot')),
                TextColumn::make('telegram_update_id')
                    ->label(nexus_trans('telegram-bot::messages.fields.telegram_update_id')),
                TextColumn::make('telegram_id')
                    ->label(nexus_trans('telegram-bot::messages.fields.telegram_id'))
                    ->searchable(),
                TextColumn::make('message_text')
                    ->label(nexus_trans('telegram-bot::messages.fields.message_text'))
                    ->limit(60),
                IconColumn::make('handled')
                    ->label(nexus_trans('telegram-bot::messages.fields.handled'))
                    ->boolean(),
                TextColumn::make('error')
                    ->label(nexus_trans('telegram-bot::messages.fields.error'))
                    ->limit(50),
                TextColumn::make('created_at')
                    ->label(nexus_trans('telegram-bot::messages.fields.created_at')),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                DeleteAction::make(),
            ])
            ->toolbarActions([
                DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageTelegramBotUpdates::route('/'),
        ];
    }
}
