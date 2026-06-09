<?php

use Illuminate\Support\Facades\Route;
use NexusPlugin\TelegramBot\Http\Controllers\BindController;
use NexusPlugin\TelegramBot\Http\Controllers\WebhookController;

Route::post('tg-webhook/{path}', [WebhookController::class, 'handle'])
    ->name('telegram-bot.webhook');

Route::group(['prefix' => 'telegram-bot', 'middleware' => ['auth.nexus:nexus-web']], function () {
    Route::get('{bot}/bind', [BindController::class, 'show'])
        ->whereNumber('bot')
        ->name('telegram-bot.bind');
});
