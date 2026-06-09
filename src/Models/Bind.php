<?php

namespace NexusPlugin\TelegramBot\Models;

use App\Models\NexusModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Bind extends NexusModel
{
    protected $table = 'plugin_telegram_bot_binds';

    public $timestamps = true;

    protected $fillable = [
        'bot_id',
        'uid',
        'telegram_id',
        'telegram_username',
        'telegram_first_name',
        'telegram_last_name',
        'bind_token',
        'bound_at',
        'last_seen_at',
        'is_enabled',
    ];

    protected $casts = [
        'bound_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'is_enabled' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (Bind $bind) {
            if (!$bind->bind_token && !$bind->telegram_id) {
                $bind->bind_token = self::newToken();
            }
        });

        static::saving(function (Bind $bind) {
            $bind->telegram_username = trim((string) ($bind->telegram_username ?? ''));
            $bind->telegram_first_name = trim((string) ($bind->telegram_first_name ?? ''));
            $bind->telegram_last_name = trim((string) ($bind->telegram_last_name ?? ''));
            $bind->is_enabled = (bool) $bind->is_enabled;
        });
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class, 'bot_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uid');
    }

    public static function makeToken(Bot $bot, int $uid): self
    {
        $bind = self::query()->firstOrNew([
            'bot_id' => $bot->id,
            'uid' => $uid,
        ]);

        if (!$bind->exists || !$bind->telegram_id) {
            $bind->bind_token = self::newToken();
        }

        $bind->is_enabled = true;
        $bind->save();

        return $bind;
    }

    public static function newToken(): string
    {
        do {
            $token = Str::random(48);
        } while (self::query()->where('bind_token', $token)->exists());

        return $token;
    }

    public function getDisplayNameAttribute(): string
    {
        $parts = array_filter([
            $this->telegram_first_name,
            $this->telegram_last_name,
        ]);

        $name = trim(implode(' ', $parts));
        if ($this->telegram_username) {
            $name .= ($name ? ' ' : '') . '@' . ltrim($this->telegram_username, '@');
        }

        return $name ?: (string) $this->telegram_id;
    }
}
