<?php

namespace NexusPlugin\TelegramBot\Models;

use App\Models\NexusModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UpdateLog extends NexusModel
{
    protected $table = 'plugin_telegram_bot_updates';

    public $timestamps = true;

    protected $fillable = [
        'bot_id',
        'telegram_update_id',
        'telegram_id',
        'message_text',
        'payload',
        'handled',
        'error',
    ];

    protected $casts = [
        'payload' => 'array',
        'handled' => 'boolean',
    ];

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class, 'bot_id');
    }
}
