<?php

namespace NexusPlugin\TelegramBot\Models;

use App\Models\NexusModel;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Telegram\Bot\Api;

class Bot extends NexusModel
{
    protected $table = 'plugin_telegram_bot_bots';

    public $timestamps = true;

    protected $fillable = [
        'name',
        'token',
        'username',
        'webhook_path',
        'webhook_secret',
        'allowed_updates',
        'is_enabled',
        'last_webhook_set_at',
        'last_error',
    ];

    protected $hidden = [
        'token',
        'webhook_secret',
    ];

    protected $casts = [
        'allowed_updates' => 'array',
        'is_enabled' => 'boolean',
        'last_webhook_set_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Bot $bot) {
            if (!$bot->webhook_path) {
                $bot->webhook_path = Str::random(40);
            }
            if (!$bot->webhook_secret) {
                $bot->webhook_secret = Str::random(48);
            }
            if (!$bot->allowed_updates) {
                $bot->allowed_updates = ['message'];
            }
        });

        static::saving(function (Bot $bot) {
            $bot->name = trim((string) $bot->name);
            $bot->token = trim((string) $bot->token);
            $bot->username = trim((string) $bot->username) ?: null;
            $bot->webhook_path = trim((string) $bot->webhook_path);
            $bot->webhook_secret = trim((string) $bot->webhook_secret);
            $bot->is_enabled = (bool) $bot->is_enabled;
        });
    }

    public function binds(): HasMany
    {
        return $this->hasMany(Bind::class, 'bot_id');
    }

    public function updates(): HasMany
    {
        return $this->hasMany(UpdateLog::class, 'bot_id');
    }

    public function api(): Api
    {
        return new Api($this->token);
    }

    public function getWebhookUrlAttribute(): string
    {
        return rtrim(Setting::getBaseUrl(), '/') . '/tg-webhook/' . $this->webhook_path;
    }

    public function getTelegramStartUrl(?string $token = null): string
    {
        if (!$this->username) {
            return '';
        }

        $url = 'https://t.me/' . ltrim($this->username, '@');
        if ($token) {
            $url .= '?start=' . urlencode($token);
        }

        return $url;
    }

    public static function enabledOptions(): array
    {
        return static::query()
            ->where('is_enabled', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
