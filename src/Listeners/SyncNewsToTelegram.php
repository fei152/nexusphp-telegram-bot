<?php

namespace NexusPlugin\TelegramBot\Listeners;

use App\Events\NewsCreated;
use NexusPlugin\TelegramBot\Services\TelegramBotService;

class SyncNewsToTelegram
{
    public function handle(NewsCreated $event): void
    {
        if ($event->model) {
            app(TelegramBotService::class)->syncNewsToAnnouncementChannel($event->model);
        }
    }
}
