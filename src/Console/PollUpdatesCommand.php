<?php

namespace NexusPlugin\TelegramBot\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use NexusPlugin\TelegramBot\Models\Bot;
use NexusPlugin\TelegramBot\Services\TelegramBotService;
use Throwable;

class PollUpdatesCommand extends Command
{
    protected $signature = 'telegram-bot:poll
        {--bot= : Bot ID to poll. Defaults to all enabled bots.}
        {--once : Poll once and exit.}
        {--timeout=30 : Long-poll timeout in seconds.}
        {--limit=100 : Maximum updates per request.}
        {--sleep=1 : Seconds to sleep between polling loops.}';

    protected $description = 'Poll Telegram updates for bots without HTTPS webhook.';

    public function handle(TelegramBotService $service): int
    {
        do {
            $bots = Bot::query()
                ->where('is_enabled', true)
                ->when($this->option('bot'), fn ($query, $id) => $query->whereKey($id))
                ->get();

            foreach ($bots as $bot) {
                $this->pollBot($bot, $service);
            }

            if ($this->option('once')) {
                break;
            }

            sleep(max(0, (int) $this->option('sleep')));
        } while (true);

        return self::SUCCESS;
    }

    private function pollBot(Bot $bot, TelegramBotService $service): void
    {
        $cacheKey = $this->offsetCacheKey($bot);
        $offset = Cache::get($cacheKey);

        $params = [
            'timeout' => max(0, (int) $this->option('timeout')),
            'limit' => max(1, min(100, (int) $this->option('limit'))),
        ];

        if ($offset) {
            $params['offset'] = (int) $offset;
        }

        if (!empty($bot->allowed_updates)) {
            $params['allowed_updates'] = $bot->allowed_updates;
        }

        try {
            $updates = $bot->api()->getUpdates($params, false);
        } catch (Throwable $throwable) {
            $bot->update(['last_error' => $this->sanitize($throwable)]);
            $this->error(sprintf('Bot %s poll failed: %s', $bot->id, $bot->last_error));
            return;
        }

        foreach ($updates as $update) {
            $payload = method_exists($update, 'toArray') ? $update->toArray() : (array) $update;
            $updateId = (int) data_get($payload, 'update_id');

            try {
                $service->handleWebhook($bot, $payload);
                if ($updateId > 0) {
                    Cache::forever($cacheKey, $updateId + 1);
                }
            } catch (Throwable $throwable) {
                $this->error(sprintf('Bot %s update %s failed: %s', $bot->id, $updateId ?: '-', $this->sanitize($throwable)));
            }
        }

        if ($updates) {
            $this->info(sprintf('Bot %s handled %d update(s).', $bot->id, count($updates)));
        }
    }

    private function offsetCacheKey(Bot $bot): string
    {
        return sprintf('telegram_bot:%s:poll_offset', $bot->id);
    }

    private function sanitize(Throwable $throwable): string
    {
        $message = $throwable->getMessage();
        $message = preg_replace('#https://api\.telegram\.org/bot[^/\s]+/#', 'https://api.telegram.org/bot***/', $message);
        $message = preg_replace('/bot\d+:[A-Za-z0-9_-]+/', 'bot***', $message);

        return $message ?: get_class($throwable);
    }
}
