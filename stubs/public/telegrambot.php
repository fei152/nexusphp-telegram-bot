<?php

require '../include/bittorrent.php';

dbconn();
loggedinorreturn();

function tg_bot_e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function tg_bot_trans(string $key): string
{
    return \NexusPlugin\TelegramBot\Support\Translator::trans($key);
}

$uid = (int) $CURUSER['id'];
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'regenerate') {
    $botId = (int) ($_POST['bot_id'] ?? 0);
    $bot = \NexusPlugin\TelegramBot\Models\Bot::query()->where('is_enabled', true)->find($botId);
    if ($bot) {
        $bind = \NexusPlugin\TelegramBot\Models\Bind::makeToken($bot, $uid);
        if (!$bind->telegram_id) {
            $bind->update(['bind_token' => \NexusPlugin\TelegramBot\Models\Bind::newToken()]);
            $notice = tg_bot_trans('user.token_refreshed');
        }
    }
}

$bots = \NexusPlugin\TelegramBot\Models\Bot::query()->where('is_enabled', true)->orderBy('id')->get();

stdhead(tg_bot_trans('user.title'));
begin_main_frame();

print('<table border="0" cellspacing="0" cellpadding="5" width="' . CONTENT_WIDTH . '">');
print('<tr><td colspan="2" class="colhead">' . tg_bot_e(tg_bot_trans('user.title')) . '</td></tr>');
if ($notice) {
    print('<tr><td colspan="2" class="heading" align="center"><font color="red">' . tg_bot_e($notice) . '</font></td></tr>');
}
print('<tr><td colspan="2">' . tg_bot_e(tg_bot_trans('user.intro')) . '</td></tr>');

if ($bots->isEmpty()) {
    print('<tr><td colspan="2">' . tg_bot_e(tg_bot_trans('user.no_enabled_bot')) . '</td></tr>');
} else {
    foreach ($bots as $bot) {
        $bind = \NexusPlugin\TelegramBot\Models\Bind::query()->where('bot_id', $bot->id)->where('uid', $uid)->first();
        if (!$bind) {
            $bind = \NexusPlugin\TelegramBot\Models\Bind::makeToken($bot, $uid);
        }

        print('<tr><td colspan="2" class="rowhead"><b>' . tg_bot_e($bot->name) . '</b></td></tr>');
        tr_small(tg_bot_trans('user.bot'), tg_bot_e($bot->username ? '@' . ltrim($bot->username, '@') : $bot->name), 1);

        if ($bind->telegram_id) {
            tr_small(tg_bot_trans('user.status'), '<font color="green">' . tg_bot_e(tg_bot_trans('user.status_bound')) . '</font>', 1);
            tr_small(tg_bot_trans('user.telegram_account'), tg_bot_e($bind->display_name . ' (' . $bind->telegram_id . ')'), 1);
            if ($bind->bound_at) {
                tr_small(tg_bot_trans('fields.bound_at'), tg_bot_e($bind->bound_at), 1);
            }
        } else {
            $command = '/bind ' . $bind->bind_token;
            $url = $bot->getTelegramStartUrl($bind->bind_token);
            tr_small(tg_bot_trans('user.status'), '<font color="red">' . tg_bot_e(tg_bot_trans('user.status_unbound')) . '</font>', 1);
            tr_small(tg_bot_trans('user.bind_command'), '<code>' . tg_bot_e($command) . '</code>', 1);
            if ($url) {
                tr_small(tg_bot_trans('user.bind_link'), '<a class="faqlink" href="' . tg_bot_e($url) . '" target="_blank" rel="noopener">' . tg_bot_e($url) . '</a>', 1);
            }
            tr_small('', '<form method="post" action="telegrambot.php"><input type="hidden" name="action" value="regenerate" /><input type="hidden" name="bot_id" value="' . (int) $bot->id . '" /><input type="submit" value="' . tg_bot_e(tg_bot_trans('user.regenerate_token')) . '" /></form>', 1);
        }
    }
}

print('</table>');
end_main_frame();
stdfoot();
