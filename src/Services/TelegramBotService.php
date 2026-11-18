<?php

namespace NexusPlugin\TelegramBot\Services;

use App\Enums\ModelEventEnum;
use App\Models\News;
use App\Models\Peer;
use App\Models\Setting;
use App\Models\Snatch;
use App\Models\Torrent;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Nexus\Database\NexusDB;
use RuntimeException;
use Throwable;
use NexusPlugin\TelegramBot\Models\Bind;
use NexusPlugin\TelegramBot\Models\Bot;
use NexusPlugin\TelegramBot\Models\UpdateLog;
use NexusPlugin\TelegramBot\Support\Translator;

class TelegramBotService
{
    public function announcementSettings(): array
    {
        return [
            'announcement_sync_group_pin' => $this->settingIsYes('telegram_bot.announcement_sync_group_pin'),
            'announcement_group_chat_id' => trim((string) Setting::getByName('telegram_bot.announcement_group_chat_id', '')),
            'announcement_sync_channel' => $this->settingIsYes('telegram_bot.announcement_sync_channel'),
            'announcement_channel_chat_id' => trim((string) Setting::getByName('telegram_bot.announcement_channel_chat_id', '')),
        ];
    }

    public function saveAnnouncementSettings(array $data): void
    {
        $this->saveSetting('telegram_bot.announcement_sync_group_pin', !empty($data['announcement_sync_group_pin']) ? 'yes' : 'no');
        $this->saveSetting('telegram_bot.announcement_group_chat_id', trim((string) ($data['announcement_group_chat_id'] ?? '')));
        $this->saveSetting('telegram_bot.announcement_sync_channel', !empty($data['announcement_sync_channel']) ? 'yes' : 'no');
        $this->saveSetting('telegram_bot.announcement_channel_chat_id', trim((string) ($data['announcement_channel_chat_id'] ?? '')));
    }

    public function handleWebhook(Bot $bot, array $payload): void
    {
        $message = data_get($payload, 'message') ?: data_get($payload, 'edited_message');
        $text = trim((string) data_get($message, 'text', ''));
        $telegramId = (string) data_get($message, 'from.id', '');

        $log = UpdateLog::query()->create([
            'bot_id' => $bot->id,
            'telegram_update_id' => data_get($payload, 'update_id'),
            'telegram_id' => $telegramId ?: null,
            'message_text' => $text ?: null,
            'payload' => $payload,
            'handled' => false,
        ]);

        try {
            if (!$message || !$telegramId) {
                $log->update(['handled' => true]);
                return;
            }

            $this->touchSeen($bot, $telegramId, (array) data_get($message, 'from', []));

            if ($text === '') {
                $log->update(['handled' => true]);
                return;
            }

            $this->handleText($bot, $message, $text);
            $log->update(['handled' => true]);
        } catch (Throwable $throwable) {
            $error = $this->sanitizeTelegramError($throwable);
            $log->update(['error' => $error]);
            $bot->update(['last_error' => $error]);
            throw $throwable;
        }
    }

    public function setWebhook(Bot $bot): void
    {
        $params = [
            'url' => $bot->webhook_url,
            'secret_token' => $bot->webhook_secret,
        ];

        if (!empty($bot->allowed_updates)) {
            $params['allowed_updates'] = $bot->allowed_updates;
        }

        try {
            $bot->api()->setWebhook($params);
            $bot->update([
                'last_webhook_set_at' => now(),
                'last_error' => null,
            ]);
        } catch (Throwable $throwable) {
            $this->failBotAction($bot, $throwable);
        }
    }

    public function deleteWebhook(Bot $bot): void
    {
        try {
            $bot->api()->deleteWebhook();
            $bot->update(['last_error' => null]);
        } catch (Throwable $throwable) {
            $this->failBotAction($bot, $throwable);
        }
    }

    public function refreshBotUsername(Bot $bot): void
    {
        try {
            $me = $bot->api()->getMe();
            $username = is_array($me) ? ($me['username'] ?? null) : data_get($me, 'username');
            if ($username) {
                $bot->update(['username' => $username, 'last_error' => null]);
            }
        } catch (Throwable $throwable) {
            $this->failBotAction($bot, $throwable);
        }
    }

    public function sendMessage(Bot $bot, string|int $chatId, string $text, array $params = []): mixed
    {
        return $bot->api()->sendMessage(array_merge([
            'chat_id' => $chatId,
            'text' => $text,
        ], $params));
    }

    public function syncNewsToAnnouncementChannel(News $news): void
    {
        $targets = $this->announcementTargets();
        if (empty($targets)) {
            return;
        }

        $bot = Bot::query()->where('is_enabled', true)->orderBy('id')->first();
        if (!$bot) {
            return;
        }

        $errors = [];
        try {
            foreach ($targets as $target) {
                try {
                    $sent = $this->sendMessage($bot, $target['chat_id'], $this->formatNewsMessage($news), [
                        'disable_web_page_preview' => true,
                    ]);

                    if ($target['pin']) {
                        $messageId = data_get($sent, 'message_id');
                        if ($messageId) {
                            $bot->api()->pinChatMessage([
                                'chat_id' => $target['chat_id'],
                                'message_id' => $messageId,
                                'disable_notification' => true,
                            ]);
                        }
                    }
                } catch (Throwable $throwable) {
                    $errors[] = $target['chat_id'] . ': ' . $this->sanitizeTelegramError($throwable);
                }
            }

            $bot->update(['last_error' => empty($errors) ? null : implode("\n", $errors)]);
        } catch (Throwable $throwable) {
            $bot->update(['last_error' => $this->sanitizeTelegramError($throwable)]);
        }
    }

    private function handleText(Bot $bot, array $message, string $text): void
    {
        $chatId = data_get($message, 'chat.id');
        $replyChatId = $this->replyChatId($message);
        [$command, $argument] = $this->parseCommand($text);

        match ($command) {
            '/start', '/bind' => $this->handleBindCommand($bot, $message, $argument),
            '/whoami', '/id' => $this->handleWhoamiCommand($bot, $message),
            '/me', '/stats' => $this->handleMeCommand($bot, $message),
            '/profile' => $this->handleProfileCommand($bot, $message),
            '/unbind' => $this->handleUnbindCommand($bot, $message),
            '/search' => $this->handleSearchCommand($bot, $message, $argument),
            '/torrent' => $this->handleTorrentCommand($bot, $message, $argument),
            '/user' => $this->handleAdminUserCommand($bot, $message, $argument),
            '/tgstats' => $this->handleTelegramStatsCommand($bot, $message),
            '/banlist' => $this->handleBanListCommand($bot, $message, $argument),
            '/ban' => $this->handleBanLookupCommand($bot, $message, $argument),
            '/announce' => $this->handleAnnounceCommand($bot, $message, $argument),
            '/announce-channel' => $this->handleAnnounceChannelCommand($bot, $message, $argument),
            '/help' => $this->handleHelpCommand($bot, $message),
            default => $this->reply($bot, $chatId, $this->trans('messages.unknown_command')),
        };
    }

    private function handleBindCommand(Bot $bot, array $message, string $token): void
    {
        $chatId = data_get($message, 'chat.id');
        if ($token === '') {
            $this->reply($bot, $chatId, $this->trans('messages.bind_token_required'));
            return;
        }

        $bind = Bind::query()
            ->where('bot_id', $bot->id)
            ->where('bind_token', $token)
            ->where('is_enabled', true)
            ->first();

        if (!$bind) {
            $this->reply($bot, $chatId, $this->trans('messages.bind_token_invalid'));
            return;
        }

        $user = User::query()->find($bind->uid);
        $locale = $user?->locale;
        $telegramId = (string) data_get($message, 'from.id');
        $conflict = Bind::query()
            ->where('bot_id', $bot->id)
            ->where('telegram_id', $telegramId)
            ->where('id', '<>', $bind->id)
            ->first();

        if ($conflict) {
            $this->reply($bot, $chatId, $this->trans('messages.telegram_already_bound', $locale));
            return;
        }

        $bind->fill($this->telegramAttributes($message));
        $bind->bind_token = null;
        $bind->bound_at = now();
        $bind->last_seen_at = now();
        $bind->save();

        $username = $user?->username ?: ('UID ' . $bind->uid);
        $this->reply($bot, $chatId, sprintf($this->trans('messages.bind_success', $locale), $username));
    }

    private function handleWhoamiCommand(Bot $bot, array $message): void
    {
        $chatId = $this->replyChatId($message);
        $telegramId = (string) data_get($message, 'from.id');
        $bind = Bind::query()
            ->where('bot_id', $bot->id)
            ->where('telegram_id', $telegramId)
            ->where('is_enabled', true)
            ->first();

        if (!$bind) {
            $this->reply($bot, $chatId, sprintf($this->trans('messages.not_bound'), $telegramId));
            return;
        }

        $user = User::query()->find($bind->uid);
        $username = $user?->username ?: ('UID ' . $bind->uid);
        $this->reply($bot, $chatId, sprintf($this->trans('messages.whoami', $user?->locale), $telegramId, $username));
    }

    private function handleMeCommand(Bot $bot, array $message): void
    {
        $chatId = $this->replyChatId($message);
        $bind = $this->boundBind($bot, $message);
        if (!$bind) {
            $this->replyNotBound($bot, $chatId, $message);
            return;
        }

        $user = User::query()->find($bind->uid);
        if (!$user) {
            $this->reply($bot, $chatId, '绑定的站点用户不存在。');
            return;
        }

        $this->reply($bot, $chatId, $this->formatUserStats($user));
    }

    private function handleProfileCommand(Bot $bot, array $message): void
    {
        $chatId = $this->replyChatId($message);
        $bind = $this->boundBind($bot, $message);
        if (!$bind) {
            $this->replyNotBound($bot, $chatId, $message);
            return;
        }

        $this->reply($bot, $chatId, $this->userUrl((int) $bind->uid));
    }

    private function handleUnbindCommand(Bot $bot, array $message): void
    {
        $chatId = $this->replyChatId($message);
        $bind = $this->boundBind($bot, $message);
        if (!$bind) {
            $this->replyNotBound($bot, $chatId, $message);
            return;
        }

        $bind->telegram_id = null;
        $bind->telegram_username = '';
        $bind->telegram_first_name = '';
        $bind->telegram_last_name = '';
        $bind->bound_at = null;
        $bind->last_seen_at = now();
        $bind->bind_token = Bind::newToken();
        $bind->save();

        $this->reply($bot, $chatId, '已解除绑定。需要重新绑定时，请回站内重新生成绑定命令。');
    }

    private function handleSearchCommand(Bot $bot, array $message, string $keyword): void
    {
        $chatId = $this->replyChatId($message);
        $bind = $this->boundBind($bot, $message);
        if (!$bind) {
            $this->replyNotBound($bot, $chatId, $message);
            return;
        }

        $keyword = trim($keyword);
        if ($keyword === '') {
            $this->reply($bot, $chatId, '用法：/search 关键词');
            return;
        }

        $user = User::query()->find($bind->uid);
        if (!$user) {
            $this->reply($bot, $chatId, '绑定的站点用户不存在。');
            return;
        }

        $terms = array_values(array_filter(preg_split('/\s+/', $keyword)));
        $query = Torrent::query()
            ->select([
                'torrents.id',
                'torrents.name',
                'torrents.small_descr',
                'torrents.size',
                'torrents.seeders',
                'torrents.leechers',
                'torrents.times_completed',
                'torrents.added',
                'torrents.visible',
                'torrents.banned',
                'torrents.approval_status',
                'categories.mode as category_mode',
            ])
            ->leftJoin('categories', 'torrents.category', '=', 'categories.id')
            ->where('torrents.visible', Torrent::VISIBLE_YES)
            ->where('torrents.banned', Torrent::BANNED_NO)
            ->where(function (Builder $builder) use ($terms) {
                foreach ($terms as $term) {
                    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $term) . '%';
                    $builder->where(function (Builder $nested) use ($like) {
                        $nested->where('torrents.name', 'like', $like)
                            ->orWhere('torrents.small_descr', 'like', $like)
                            ->orWhere('torrents.save_as', 'like', $like);
                    });
                }
            });

        $this->applyTorrentVisibility($query, (int) $user->id);

        $torrents = $query
            ->orderByDesc('torrents.seeders')
            ->orderByDesc('torrents.id')
            ->limit(5)
            ->get();

        if ($torrents->isEmpty()) {
            $this->reply($bot, $chatId, sprintf('没有找到可见种子：%s', $keyword));
            return;
        }

        $lines = [sprintf('搜索：%s', $keyword)];
        foreach ($torrents as $torrent) {
            $lines[] = $this->formatTorrentSummary($torrent);
        }

        $this->reply($bot, $chatId, implode("\n\n", $lines));
        $this->notifyPrivateReplyIfNeeded($bot, $message);
    }

    private function handleTorrentCommand(Bot $bot, array $message, string $argument): void
    {
        $chatId = $this->replyChatId($message);
        $bind = $this->boundBind($bot, $message);
        if (!$bind) {
            $this->replyNotBound($bot, $chatId, $message);
            return;
        }

        $id = (int) trim($argument);
        if ($id <= 0) {
            $this->reply($bot, $chatId, '用法：/torrent 种子ID');
            return;
        }

        $query = Torrent::query()
            ->select([
                'torrents.id',
                'torrents.name',
                'torrents.small_descr',
                'torrents.size',
                'torrents.seeders',
                'torrents.leechers',
                'torrents.times_completed',
                'torrents.added',
                'torrents.visible',
                'torrents.banned',
                'torrents.approval_status',
                'categories.mode as category_mode',
            ])
            ->leftJoin('categories', 'torrents.category', '=', 'categories.id')
            ->where('torrents.id', $id)
            ->where('torrents.visible', Torrent::VISIBLE_YES)
            ->where('torrents.banned', Torrent::BANNED_NO);

        $this->applyTorrentVisibility($query, (int) $bind->uid);

        $torrent = $query->first();
        if (!$torrent) {
            $this->reply($bot, $chatId, '没有找到这个种子，或你没有权限查看。');
            return;
        }

        $this->reply($bot, $chatId, $this->formatTorrentSummary($torrent, true));
        $this->notifyPrivateReplyIfNeeded($bot, $message);
    }

    private function handleAdminUserCommand(Bot $bot, array $message, string $argument): void
    {
        $chatId = $this->replyChatId($message);
        $viewerBind = $this->boundBind($bot, $message);
        if (!$viewerBind) {
            $this->replyNotBound($bot, $chatId, $message);
            return;
        }

        if (!$this->isStaff((int) $viewerBind->uid)) {
            $this->reply($bot, $chatId, '权限不足。');
            return;
        }

        $target = $this->findTargetUser($bot, $message, $argument);
        if (!$target) {
            $this->reply($bot, $chatId, '用法：/user 用户名、UID、@Telegram用户名，或回复对方消息发送 /user');
            return;
        }

        $this->reply($bot, $chatId, $this->formatAdminUserInfo($target));
        $this->notifyPrivateReplyIfNeeded($bot, $message);
    }

    private function handleTelegramStatsCommand(Bot $bot, array $message): void
    {
        $chatId = $this->replyChatId($message);
        $viewerBind = $this->boundBind($bot, $message);
        if (!$viewerBind) {
            $this->replyNotBound($bot, $chatId, $message);
            return;
        }

        if (!$this->isStaff((int) $viewerBind->uid)) {
            $this->reply($bot, $chatId, '权限不足。');
            return;
        }

        $bound = Bind::query()->where('bot_id', $bot->id)->whereNotNull('telegram_id')->count();
        $updates = UpdateLog::query()->where('bot_id', $bot->id)->count();
        $errors = UpdateLog::query()->where('bot_id', $bot->id)->whereNotNull('error')->count();

        $this->reply($bot, $chatId, sprintf(
            "Telegram 机器人统计\n已绑定用户：%s\n更新记录：%s\n错误记录：%s\n最近错误：%s",
            number_format($bound),
            number_format($updates),
            number_format($errors),
            $bot->last_error ?: '无'
        ));
    }

    private function handleBanListCommand(Bot $bot, array $message, string $argument): void
    {
        $chatId = $this->replyChatId($message);
        $viewerBind = $this->boundBind($bot, $message);
        if (!$viewerBind) {
            $this->replyNotBound($bot, $chatId, $message);
            return;
        }

        if (!$this->canViewBans((int) $viewerBind->uid)) {
            $this->reply($bot, $chatId, '权限不足。');
            return;
        }

        $limit = (int) trim($argument);
        $limit = $limit > 0 ? min($limit, 20) : 10;
        $rows = DB::table('bans')->orderByDesc('added')->limit($limit)->get();
        if ($rows->isEmpty()) {
            $this->reply($bot, $chatId, '当前没有 IP 封禁记录。');
            return;
        }

        $lines = [sprintf('最近 %d 条 IP 封禁：', $limit)];
        foreach ($rows as $row) {
            $lines[] = sprintf(
                '#%s %s - %s | %s | %s',
                $row->id,
                long2ip((int) $row->first),
                long2ip((int) $row->last),
                $row->added,
                $this->limitText((string) $row->comment, 80)
            );
        }

        $this->reply($bot, $chatId, implode("\n", $lines));
        $this->notifyPrivateReplyIfNeeded($bot, $message);
    }

    private function handleBanLookupCommand(Bot $bot, array $message, string $argument): void
    {
        $chatId = $this->replyChatId($message);
        $viewerBind = $this->boundBind($bot, $message);
        if (!$viewerBind) {
            $this->replyNotBound($bot, $chatId, $message);
            return;
        }

        if (!$this->canViewBans((int) $viewerBind->uid)) {
            $this->reply($bot, $chatId, '权限不足。');
            return;
        }

        $ip = trim($argument);
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $this->reply($bot, $chatId, '用法：/ban IPv4地址');
            return;
        }

        $ipLong = sprintf('%u', ip2long($ip));
        $rows = DB::table('bans')
            ->where('first', '<=', $ipLong)
            ->where('last', '>=', $ipLong)
            ->orderByDesc('added')
            ->get();

        if ($rows->isEmpty()) {
            $this->reply($bot, $chatId, sprintf('%s 未被 IP 封禁列表命中。', $ip));
            return;
        }

        $lines = [sprintf('%s 命中 %d 条 IP 封禁：', $ip, $rows->count())];
        foreach ($rows as $row) {
            $admin = User::query()->find($row->addedby);
            $lines[] = sprintf(
                '#%s %s - %s | 添加：%s | 管理员：%s | 原因：%s',
                $row->id,
                long2ip((int) $row->first),
                long2ip((int) $row->last),
                $row->added,
                $admin?->username ?: $row->addedby,
                $this->limitText((string) $row->comment, 180)
            );
        }

        $this->reply($bot, $chatId, implode("\n", $lines));
        $this->notifyPrivateReplyIfNeeded($bot, $message);
    }

    private function handleAnnounceChannelCommand(Bot $bot, array $message, string $argument): void
    {
        $chatId = $this->replyChatId($message);
        $viewerBind = $this->boundBind($bot, $message);
        if (!$viewerBind) {
            $this->replyNotBound($bot, $chatId, $message);
            return;
        }

        if (!$this->canManageNews((int) $viewerBind->uid)) {
            $this->reply($bot, $chatId, '权限不足。');
            return;
        }

        $parts = array_values(array_filter(preg_split('/\s+/', trim($argument)) ?: [], fn ($part) => $part !== ''));
        $action = strtolower((string) ($parts[0] ?? ''));
        if ($action === '' || $action === 'list') {
            $this->reply($bot, $chatId, $this->formatAnnouncementTargetsHelp());
            return;
        }

        if ($action === 'off') {
            $this->saveSetting('telegram_bot.announcement_sync_group_pin', 'no');
            $this->saveSetting('telegram_bot.announcement_sync_channel', 'no');
            $this->reply($bot, $chatId, '已关闭公告同步开关，已保存的群聊和频道目标不会删除。');
            return;
        }

        $targetIndex = in_array($action, ['add', 'set', 'remove', 'del', 'delete'], true) ? 1 : 0;
        $target = (string) ($parts[$targetIndex] ?? '');
        if ($target === '') {
            $this->reply($bot, $chatId, "用法：\n/announce-channel add here pin\n/announce-channel add @频道 nopin\n/announce-channel remove @频道\n/announce-channel list\n/announce-channel off");
            return;
        }

        if (in_array($target, ['here', 'this'], true)) {
            $currentChatId = data_get($message, 'chat.id');
            if (!$currentChatId || (string) data_get($message, 'chat.type', 'private') === 'private') {
                $this->reply($bot, $chatId, '请在目标群组里发送 /announce-channel add here pin，或直接指定 @频道/群ID。');
                return;
            }
            $target = (string) $currentChatId;
        }

        if (in_array($action, ['remove', 'del', 'delete'], true)) {
            $this->removeAnnouncementTarget($target);
            $this->reply($bot, $chatId, sprintf('已移除公告同步目标：%s。', $target));
            return;
        }

        $isGroupTarget = in_array('pin', $parts, true) || (string) data_get($message, 'chat.type', 'private') !== 'private';
        if ($isGroupTarget) {
            $this->saveSetting('telegram_bot.announcement_group_chat_id', $target);
            $this->reply($bot, $chatId, sprintf('已保存群聊同步目标：%s。后台打开“同步群聊置顶”后，站点公告会同步到该群并置顶。', $target));
            return;
        }

        $this->saveSetting('telegram_bot.announcement_channel_chat_id', $target);
        $this->reply($bot, $chatId, sprintf('已保存频道同步目标：%s。后台打开“同步频道”后，站点公告会同步到该频道。', $target));
    }

    private function handleAnnounceCommand(Bot $bot, array $message, string $argument): void
    {
        $chatId = $this->replyChatId($message);
        $viewerBind = $this->boundBind($bot, $message);
        if (!$viewerBind) {
            $this->replyNotBound($bot, $chatId, $message);
            return;
        }

        if (!$this->canManageNews((int) $viewerBind->uid)) {
            $this->reply($bot, $chatId, '权限不足。');
            return;
        }

        if ((string) data_get($message, 'chat.type', 'private') !== 'private') {
            $this->reply($bot, data_get($message, 'chat.id'), '发首页公告请私聊机器人执行，避免群里误触。');
            return;
        }

        [$title, $body] = $this->parseAnnouncement($argument);
        if ($title === '' || $body === '') {
            $this->reply($bot, $chatId, "用法：/announce 标题 | 正文\n也可以第一行写标题，第二行开始写正文。");
            return;
        }

        $news = News::query()->create([
            'userid' => (int) $viewerBind->uid,
            'added' => now()->toDateTimeString(),
            'title' => htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
            'body' => htmlspecialchars($body, ENT_QUOTES, 'UTF-8'),
            'notify' => 'yes',
        ]);

        NexusDB::cache_del('recent_news');
        if (function_exists('fire_event')) {
            fire_event(ModelEventEnum::NEWS_CREATED, $news);
        } else {
            $this->syncNewsToAnnouncementChannel($news);
        }

        $this->reply($bot, $chatId, sprintf("首页公告已发布：%s\n%s", $title, rtrim(Setting::getBaseUrl(), '/') . '/index.php'));
    }

    private function handleHelpCommand(Bot $bot, array $message): void
    {
        $chatId = $this->replyChatId($message);
        $bind = $this->boundBind($bot, $message);
        $isStaff = $bind && $this->isStaff((int) $bind->uid);

        $lines = [
            '用户命令：',
            '/start TOKEN - 绑定账号',
            '/bind TOKEN - 绑定账号',
            '/whoami 或 /id - 查看绑定',
            '/me 或 /stats - 查看个人上传/下载/魔力等',
            '/profile - 查看个人页链接',
            '/search 关键词 - 搜索你有权限查看的种子',
            '/torrent ID - 查看种子摘要',
            '/unbind - 解除绑定',
        ];

        if ($isStaff) {
            $lines[] = '';
            $lines[] = '管理命令：';
            $lines[] = '/user 用户名/UID/@TG用户名/TG ID - 查看用户详细信息';
            $lines[] = '回复某人的消息发送 /user - 查询该 TG 绑定的站内用户';
            $lines[] = '/tgstats - 查看机器人统计';
            $lines[] = '/banlist [数量] - 查看 IP 封禁列表';
            $lines[] = '/ban IP - 精确查询 IP 是否被封禁';
            $lines[] = '/announce 标题 | 正文 - 发布首页公告';
            $lines[] = '/announce-channel add here pin - 添加当前群为公告同步目标并置顶';
            $lines[] = '/announce-channel add @频道 nopin - 添加频道为公告同步目标';
            $lines[] = '/announce-channel list/remove/off - 查看、移除或关闭公告同步';
        }

        $this->reply($bot, $chatId, implode("\n", $lines));
    }

    private function touchSeen(Bot $bot, string $telegramId, array $from): void
    {
        Bind::query()
            ->where('bot_id', $bot->id)
            ->where('telegram_id', $telegramId)
            ->update(array_merge($this->fromAttributes($from), [
                'last_seen_at' => now(),
            ]));
    }

    private function telegramAttributes(array $message): array
    {
        return array_merge($this->fromAttributes((array) data_get($message, 'from', [])), [
            'telegram_id' => (string) data_get($message, 'from.id'),
        ]);
    }

    private function fromAttributes(array $from): array
    {
        return [
            'telegram_username' => (string) ($from['username'] ?? ''),
            'telegram_first_name' => (string) ($from['first_name'] ?? ''),
            'telegram_last_name' => (string) ($from['last_name'] ?? ''),
        ];
    }

    private function parseCommand(string $text): array
    {
        [$command, $argument] = array_pad(preg_split('/\s+/', $text, 2), 2, '');
        $command = strtolower((string) preg_replace('/@.+$/', '', $command));

        return [$command, trim($argument)];
    }

    private function replyChatId(array $message): mixed
    {
        $chatType = (string) data_get($message, 'chat.type', 'private');

        return $chatType === 'private'
            ? data_get($message, 'chat.id')
            : data_get($message, 'from.id', data_get($message, 'chat.id'));
    }

    private function notifyPrivateReplyIfNeeded(Bot $bot, array $message): void
    {
        $chatType = (string) data_get($message, 'chat.type', 'private');
        if ($chatType !== 'private') {
            $this->reply($bot, data_get($message, 'chat.id'), '已私聊回复。');
        }
    }

    private function boundBind(Bot $bot, array $message): ?Bind
    {
        $telegramId = (string) data_get($message, 'from.id', '');
        if ($telegramId === '') {
            return null;
        }

        return Bind::query()
            ->where('bot_id', $bot->id)
            ->where('telegram_id', $telegramId)
            ->where('is_enabled', true)
            ->first();
    }

    private function replyNotBound(Bot $bot, mixed $chatId, array $message): void
    {
        $telegramId = (string) data_get($message, 'from.id', '');
        $this->reply($bot, $chatId, sprintf($this->trans('messages.not_bound'), $telegramId));
    }

    private function applyTorrentVisibility(Builder $query, int $uid): void
    {
        $allowedModes = array_filter([
            (int) Setting::get('main.browsecat'),
        ]);

        if (function_exists('user_can') && user_can('view_special_torrent', false, $uid)) {
            $allowedModes[] = (int) Setting::get('main.specialcat');
        }

        if ($allowedModes) {
            $query->whereIn('categories.mode', array_values(array_unique($allowedModes)));
        }
    }

    private function isStaff(int $uid): bool
    {
        return function_exists('user_can') && user_can('staffmem', false, $uid);
    }

    private function canViewBans(int $uid): bool
    {
        $user = User::query()->find($uid);

        return $user && (int) $user->class >= (int) User::CLASS_ADMINISTRATOR;
    }

    private function canManageNews(int $uid): bool
    {
        return function_exists('user_can') && user_can('newsmanage', false, $uid);
    }

    private function findTargetUser(Bot $bot, array $message, string $argument): ?User
    {
        $replyTelegramId = (string) data_get($message, 'reply_to_message.from.id', '');
        if ($replyTelegramId !== '') {
            $bind = Bind::query()
                ->where('bot_id', $bot->id)
                ->where('telegram_id', $replyTelegramId)
                ->where('is_enabled', true)
                ->first();
            if ($bind) {
                return User::query()->find($bind->uid);
            }
        }

        $argument = trim($argument);
        if ($argument === '') {
            return null;
        }

        $normalized = ltrim($argument, '@');

        if (ctype_digit($normalized)) {
            $user = User::query()->find((int) $normalized);
            if ($user) {
                return $user;
            }

            $bind = Bind::query()
                ->where('bot_id', $bot->id)
                ->where('telegram_id', $normalized)
                ->where('is_enabled', true)
                ->first();
            if ($bind) {
                return User::query()->find($bind->uid);
            }
        }

        $bind = Bind::query()
            ->where('bot_id', $bot->id)
            ->where('telegram_username', $normalized)
            ->where('is_enabled', true)
            ->first();
        if ($bind) {
            return User::query()->find($bind->uid);
        }

        return User::query()
            ->where('username', $argument)
            ->orWhere('username', $normalized)
            ->first();
    }

    private function formatUserStats(User $user): string
    {
        return sprintf(
            "个人信息\n用户：%s (UID %s)\n等级：%s\n上传：%s\n下载：%s\n分享率：%s\n魔力：%s\n做种积分：%s\n邀请：%s\n个人页：%s",
            $user->username,
            $user->id,
            User::getClassText($user->class),
            mksize((float) $user->uploaded),
            mksize((float) $user->downloaded),
            $this->ratio((float) $user->uploaded, (float) $user->downloaded),
            number_format((float) $user->seedbonus, 1),
            number_format((float) ($user->seed_points ?? 0), 1),
            (int) $user->invites,
            $this->userUrl((int) $user->id)
        );
    }

    private function formatTorrentSummary(Torrent $torrent, bool $detail = false): string
    {
        $summary = trim((string) $torrent->small_descr);
        $lines = [
            sprintf('#%s %s', $torrent->id, $torrent->name),
            sprintf('大小：%s | 做种：%s | 下载：%s | 完成：%s', mksize((float) $torrent->size), (int) $torrent->seeders, (int) $torrent->leechers, (int) $torrent->times_completed),
        ];

        if ($summary !== '') {
            $lines[] = '简介：' . $this->limitText($summary, $detail ? 300 : 120);
        }

        $lines[] = $this->torrentUrl((int) $torrent->id);

        return implode("\n", $lines);
    }

    private function formatAdminUserInfo(User $user): string
    {
        $seeding = Peer::query()->where('userid', $user->id)->where('seeder', Peer::SEEDER_YES)->count();
        $leeching = Peer::query()->where('userid', $user->id)->where('seeder', Peer::SEEDER_NO)->count();
        $finished = Snatch::query()->where('userid', $user->id)->where('finished', Snatch::FINISHED_YES)->count();
        $currentSeeds = Peer::query()
            ->select(['torrents.id', 'torrents.name', 'torrents.size', 'peers.last_action'])
            ->join('torrents', 'peers.torrent', '=', 'torrents.id')
            ->where('peers.userid', $user->id)
            ->where('peers.seeder', Peer::SEEDER_YES)
            ->orderByDesc('peers.last_action')
            ->limit(8)
            ->get();

        $lines = [
            sprintf('用户信息：%s (UID %s)', $user->username, $user->id),
            sprintf('等级：%s | 状态：%s/%s', User::getClassText($user->class), $user->status, $user->enabled),
            sprintf('上传：%s | 下载：%s | 分享率：%s', mksize((float) $user->uploaded), mksize((float) $user->downloaded), $this->ratio((float) $user->uploaded, (float) $user->downloaded)),
            sprintf('魔力：%s | 做种积分：%s | 邀请：%s', number_format((float) $user->seedbonus, 1), number_format((float) ($user->seed_points ?? 0), 1), (int) $user->invites),
            sprintf('当前做种：%s | 当前下载：%s | 完成：%s', number_format($seeding), number_format($leeching), number_format($finished)),
            sprintf('注册：%s | 最近访问：%s | 最近登录：%s', $this->dateText($user->added), $this->dateText($user->last_access), $this->dateText($user->last_login)),
            $this->userUrl((int) $user->id),
        ];

        if ($currentSeeds->isNotEmpty()) {
            $lines[] = '正在做种：';
            foreach ($currentSeeds as $torrent) {
                $lines[] = sprintf('#%s %s (%s)', $torrent->id, $this->limitText($torrent->name, 80), mksize((float) $torrent->size));
            }
        }

        return implode("\n", $lines);
    }

    private function formatNewsMessage(News $news): string
    {
        $title = html_entity_decode((string) $news->title, ENT_QUOTES, 'UTF-8');
        $body = html_entity_decode((string) $news->body, ENT_QUOTES, 'UTF-8');
        $body = $this->limitText($body, 3000);

        return sprintf(
            "%s\n\n%s\n\n%s",
            $title,
            $body,
            rtrim(Setting::getBaseUrl(), '/') . '/index.php'
        );
    }

    private function parseAnnouncement(string $argument): array
    {
        $argument = trim($argument);
        if (str_contains($argument, '|')) {
            [$title, $body] = array_map('trim', explode('|', $argument, 2));

            return [$title, $body];
        }

        $parts = preg_split('/\R/u', $argument, 2);

        return [trim($parts[0] ?? ''), trim($parts[1] ?? '')];
    }

    private function announcementTargets(): array
    {
        $settings = $this->announcementSettings();
        $targets = [];

        if ($settings['announcement_sync_group_pin'] && $settings['announcement_group_chat_id'] !== '') {
            $targets[] = [
                'chat_id' => $settings['announcement_group_chat_id'],
                'pin' => true,
            ];
        }

        if ($settings['announcement_sync_channel'] && $settings['announcement_channel_chat_id'] !== '') {
            $targets[] = [
                'chat_id' => $settings['announcement_channel_chat_id'],
                'pin' => false,
            ];
        }

        $unique = [];
        foreach ($targets as $target) {
            $chatId = $target['chat_id'];
            $unique[$chatId] = [
                'chat_id' => $chatId,
                'pin' => ($unique[$chatId]['pin'] ?? false) || $target['pin'],
            ];
        }

        return array_values($unique);
    }

    private function removeAnnouncementTarget(string $target): void
    {
        if (trim((string) Setting::getByName('telegram_bot.announcement_group_chat_id', '')) === $target) {
            $this->saveSetting('telegram_bot.announcement_group_chat_id', '');
            $this->saveSetting('telegram_bot.announcement_sync_group_pin', 'no');
        }

        if (trim((string) Setting::getByName('telegram_bot.announcement_channel_chat_id', '')) === $target) {
            $this->saveSetting('telegram_bot.announcement_channel_chat_id', '');
            $this->saveSetting('telegram_bot.announcement_sync_channel', 'no');
        }
    }

    private function formatAnnouncementTargetsHelp(): string
    {
        $settings = $this->announcementSettings();
        $lines = [
            '当前公告同步设置：',
            sprintf('群聊置顶：%s，目标：%s', $settings['announcement_sync_group_pin'] ? '开启' : '关闭', $settings['announcement_group_chat_id'] ?: '未设置'),
            sprintf('频道同步：%s，目标：%s', $settings['announcement_sync_channel'] ? '开启' : '关闭', $settings['announcement_channel_chat_id'] ?: '未设置'),
        ];

        $lines[] = '';
        $lines[] = '用法：';
        $lines[] = '/announce-channel add here pin - 保存当前群为群聊置顶目标';
        $lines[] = '/announce-channel add @频道 - 保存频道目标';
        $lines[] = '/announce-channel remove @频道或群ID - 移除目标';
        $lines[] = '/announce-channel off - 关闭两个同步开关';
        $lines[] = '是否同步请在插件后台开关控制。';

        return implode("\n", $lines);
    }

    private function settingIsYes(string $name): bool
    {
        return Setting::getByName($name, 'no') === 'yes';
    }

    private function saveSetting(string $name, string $value): void
    {
        Setting::query()->updateOrCreate(['name' => $name], [
            'value' => $value,
            'autoload' => 'yes',
        ]);
        NexusDB::cache_del('nexus_settings_in_laravel');
        NexusDB::cache_del('nexus_settings_in_nexus');
    }

    private function ratio(float $uploaded, float $downloaded): string
    {
        if ($downloaded > 0) {
            return number_format(floor(($uploaded / $downloaded) * 1000) / 1000, 3);
        }

        return $uploaded > 0 ? 'Inf.' : '---';
    }

    private function dateText(mixed $value): string
    {
        if (!$value) {
            return '-';
        }

        return method_exists($value, 'toDateTimeString') ? $value->toDateTimeString() : (string) $value;
    }

    private function userUrl(int $uid): string
    {
        return rtrim(Setting::getBaseUrl(), '/') . '/userdetails.php?id=' . $uid;
    }

    private function torrentUrl(int $torrentId): string
    {
        return rtrim(Setting::getBaseUrl(), '/') . '/details.php?id=' . $torrentId;
    }

    private function limitText(string $text, int $length): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)));
        if (mb_strlen($text) <= $length) {
            return $text;
        }

        return mb_substr($text, 0, $length - 3) . '...';
    }

    private function reply(Bot $bot, mixed $chatId, string $text): void
    {
        if (!$chatId) {
            return;
        }

        $this->sendMessage($bot, $chatId, $text);
    }

    private function trans(string $key, ?string $locale = null): string
    {
        return Translator::trans($key, [], $locale);
    }

    private function failBotAction(Bot $bot, Throwable $throwable): void
    {
        $error = $this->sanitizeTelegramError($throwable);
        $bot->update(['last_error' => $error]);

        throw new RuntimeException($error, 0, $throwable);
    }

    private function sanitizeTelegramError(Throwable $throwable): string
    {
        $message = $throwable->getMessage();
        $message = preg_replace('#https://api\.telegram\.org/bot[^/\s]+/#', 'https://api.telegram.org/bot***/', $message);
        $message = preg_replace('/bot\d+:[A-Za-z0-9_-]+/', 'bot***', $message);

        return $message ?: get_class($throwable);
    }
}
