<?php

namespace App\Filament\Resources\System\TelegramBotBindResource\Pages;

use App\Filament\PageListSingle;
use App\Filament\Resources\System\TelegramBotBindResource;
use Filament\Actions\CreateAction;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;

class ManageTelegramBotBinds extends PageListSingle
{
    protected static string $resource = TelegramBotBindResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    public function getTitle(): string
    {
        return nexus_trans('telegram-bot::messages.admin.nav');
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                View::make('telegram-bot::admin-tabs')
                    ->viewData(['active' => 'binds']),
                $this->getTabsContentComponent(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
                EmbeddedTable::make(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
            ]);
    }
}
