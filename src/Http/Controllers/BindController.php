<?php

namespace NexusPlugin\TelegramBot\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use NexusPlugin\TelegramBot\Models\Bind;
use NexusPlugin\TelegramBot\Models\Bot;

class BindController extends Controller
{
    public function show(Bot $bot, Request $request)
    {
        abort_unless($bot->is_enabled, 404);

        $user = Auth::user();
        $bind = Bind::makeToken($bot, (int) $user->id);

        return view('telegram-bot::bind', [
            'bot' => $bot,
            'bind' => $bind,
            'url' => $bot->getTelegramStartUrl($bind->bind_token),
            'message' => $bind->telegram_id
                ? sprintf(nexus_trans('telegram-bot::messages.already_bound'), $bind->display_name)
                : null,
        ]);
    }
}
