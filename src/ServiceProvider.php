<?php

namespace NexusPlugin\TelegramBot;

use App\Events\NewsCreated;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Illuminate\Support\Facades\Event;
use NexusPlugin\TelegramBot\Console\PollUpdatesCommand;
use NexusPlugin\TelegramBot\Listeners\SyncNewsToTelegram;

class ServiceProvider extends BaseServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        $this->loadTranslationsFrom(__DIR__ . '/../resources/lang', Repository::ID);
        $this->loadViewsFrom(__DIR__ . '/../resources/views', Repository::ID);

        Event::listen(NewsCreated::class, [SyncNewsToTelegram::class, 'handle']);

        if ($this->app->runningInConsole()) {
            $this->commands([
                PollUpdatesCommand::class,
            ]);
        }
    }
}
