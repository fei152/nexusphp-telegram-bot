# NexusPHP Telegram 机器人插件

适用于 NexusPHP `v1.10.2` 的 Telegram 机器人插件。插件可以和其它 NexusPHP 插件并存，表名、后台资源、前台入口和设置项都使用独立命名。

当前版本：`v1.0.0`

## 功能

- 用户绑定、解绑、查看个人数据。
- 用户通过机器人搜索自己有权限查看的种子。
- 管理员查询用户资料、IP 封禁列表、指定 IP 封禁原因。
- 管理员通过机器人发布首页公告。
- 站点首页公告可同步到 Telegram 群聊并置顶。
- 站点首页公告可同步到 Telegram 频道。
- 后台提供两个独立开关：同步群聊置顶、同步频道。打开哪个同步哪个，都关闭则不推送。
- 支持 webhook，也支持无 HTTPS 环境下使用 long polling。

## 环境要求

- NexusPHP `v1.10.2`
- PHP `>=8.2 <8.6`
- Composer
- 一台可以访问 Telegram API 的服务器

## 安装

把插件源码放到 NexusPHP 项目的 `packages` 目录：

```bash
cd /path/to/nexusphp
mkdir -p packages
cp -R /path/to/nexusphp-telegram-bot packages/nexus-telegram-bot
```

注册本地 Composer 包并安装依赖：

```bash
COMPOSER_ALLOW_SUPERUSER=1 composer config repositories.telegram-bot path packages/nexus-telegram-bot
COMPOSER_ALLOW_SUPERUSER=1 composer require xiaomlove/nexusphp-telegram-bot:*
```

执行 NexusPHP 插件安装命令：

```bash
php artisan plugin install xiaomlove/nexusphp-telegram-bot
php artisan optimize:clear
```

确认目录权限：

```bash
chown -R www-data:www-data storage bootstrap/cache
```

## 后台配置

进入后台：

```text
管理后台 -> Telegram 机器人
```

先新增机器人，填写 BotFather 给的 Token，然后点“刷新机器人信息”。

公告同步设置在机器人列表顶部：

- `同步群聊置顶`：打开后，站点首页公告会发到群聊并尝试置顶。
- `群聊 ID`：目标群聊 ID。
- `同步频道`：打开后，站点首页公告会发到频道。
- `频道用户名或 ID`：例如 `@channel_name` 或 `-100...`。

两个开关都关闭时，站点公告不会同步到 Telegram。

## 轮询服务

如果站点没有 HTTPS，不要设置 Telegram webhook，使用 long polling。创建 systemd 服务：

```bash
cd /path/to/nexusphp
sudo tee /etc/systemd/system/nexusphp-telegram-bot-poll.service >/dev/null <<'EOF'
[Unit]
Description=NexusPHP Telegram Bot Poller
After=network.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/path/to/nexusphp
ExecStart=/usr/bin/php artisan telegram-bot:poll --timeout=30 --sleep=1
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF

sudo systemctl daemon-reload
sudo systemctl enable --now nexusphp-telegram-bot-poll.service
```

把 `/path/to/nexusphp` 换成真实站点目录。

如果站点有 HTTPS，可以在后台点“设置 Webhook”，不需要运行上面的轮询服务。

## 公告同步目标

群聊目标建议在目标群里设置。先把机器人加入群，给它发送消息和置顶消息权限，然后用已绑定管理员账号的 Telegram 号发送：

```text
/announce-channel add here pin
```

频道目标可以在后台直接填：

```text
@channel_name
```

也可以填频道的 `-100...` ID。机器人必须是频道管理员并拥有发消息权限。

## 常用命令

用户命令：

```text
/start TOKEN
/bind TOKEN
/whoami
/me
/profile
/search 关键词
/torrent ID
/unbind
/help
```

管理员命令：

```text
/user 用户名/UID/@TG用户名/TG ID
/tgstats
/banlist [数量]
/ban IP
/announce 标题 | 正文
/announce-channel list
/announce-channel add here pin
/announce-channel add @频道
/announce-channel remove @频道或群ID
/announce-channel off
```

管理员权限按已绑定的 NexusPHP 账号判断，不按 Telegram 身份判断。

## 并存说明

插件使用以下独立资源：

- 数据表：`plugin_telegram_bot_bots`、`plugin_telegram_bot_binds`、`plugin_telegram_bot_updates`
- 设置项前缀：`telegram_bot.`
- 后台资源：`TelegramBotResource`、`TelegramBotBindResource`、`TelegramBotUpdateResource`
- 前台入口：`public/telegrambot.php`

安装时会尝试在 `public/usercp.php` 增加一个用户中心入口。修改前会生成 `.telegram-bot-menu.bak` 备份；如果找不到兼容插入点，会跳过，不会强行改写。

## 检查

安装后建议执行：

```bash
php -l public/telegrambot.php
php -l app/Filament/Resources/System/TelegramBotResource.php
php artisan telegram-bot:poll --once
```

查看服务日志：

```bash
journalctl -u nexusphp-telegram-bot-poll.service -n 80 --no-pager
```

## 许可证

GPL-2.0-only
