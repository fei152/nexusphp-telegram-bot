<?php

namespace NexusPlugin\TelegramBot\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use NexusPlugin\TelegramBot\Models\Bot;
use NexusPlugin\TelegramBot\Services\TelegramBotService;

class WebhookController extends Controller
{
    public function handle(string $path, Request $request, TelegramBotService $service): JsonResponse
    {
        $bot = Bot::query()
            ->where('webhook_path', $path)
            ->where('is_enabled', true)
            ->firstOrFail();

        $secret = $request->header('X-Telegram-Bot-Api-Secret-Token');
        if ($bot->webhook_secret && !hash_equals($bot->webhook_secret, (string) $secret)) {
            abort(403);
        }

        $service->handleWebhook($bot, $request->all());

        return response()->json(['ok' => true]);
    }
}
