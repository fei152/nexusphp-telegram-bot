<?php

namespace NexusPlugin\TelegramBot;

use App\Models\User;
use App\Models\Setting;
use Filament\Actions\Action;
use Illuminate\Support\Facades\Artisan;
use Nexus\Plugin\BasePlugin;
use NexusPlugin\TelegramBot\Models\Bot;
use NexusPlugin\TelegramBot\Models\Bind;
use NexusPlugin\TelegramBot\Support\Translator;

class Repository extends BasePlugin
{
    public const ID = 'telegram-bot';
    public const VERSION = '1.0.0';
    public const COMPATIBLE_NP_VERSION = '1.10.2';

    public function install(): void
    {
        $this->ensureDependenciesAvailable();
        $this->ensureServiceProviderRegistered();
        $this->publishApplicationFiles();
        $this->patchUserCpMenu();
        $this->runMigrations(__DIR__ . '/../database/migrations');
        $this->seedDefaults();
        $this->clearCaches();
    }

    public function uninstall(): void
    {
        $this->runMigrations(__DIR__ . '/../database/migrations', true);
    }

    public function boot(): void
    {
        add_filter('user_profile_actions', [$this, 'addUserProfileActions'], 10, 2);
    }

    public function getId(): string
    {
        return self::ID;
    }

    public function trans($name): string
    {
        return Translator::trans($name);
    }

    public function addUserProfileActions(array $actions, ?User $profileUser = null): array
    {
        if (!$profileUser || !class_exists(Action::class)) {
            return $actions;
        }

        $actions[] = Action::make('telegram_bind_token')
            ->label($this->trans('actions.create_bind_token'))
            ->modalHeading($this->trans('actions.create_bind_token'))
            ->modalContent(function () use ($profileUser) {
                $bot = Bot::query()->where('is_enabled', true)->orderBy('id')->first();
                if (!$bot) {
                    return view('telegram-bot::bind-token', [
                        'message' => $this->trans('messages.no_enabled_bot'),
                    ]);
                }

                $bind = Bind::makeToken($bot, (int) $profileUser->id);
                if ($bind->telegram_id) {
                    return view('telegram-bot::bind-token', [
                        'message' => sprintf($this->trans('messages.already_bound'), $bind->display_name),
                    ]);
                }

                return view('telegram-bot::bind-token', [
                    'message' => null,
                    'bot' => $bot,
                    'bind' => $bind,
                    'url' => $bot->getTelegramStartUrl($bind->bind_token),
                ]);
            })
            ->modalSubmitAction(false);

        return $actions;
    }

    private function publishApplicationFiles(): void
    {
        $stubsPath = dirname(__DIR__) . '/stubs';
        if (!is_dir($stubsPath)) {
            return;
        }

        $files = [
            'app/Filament/Resources/System/TelegramBotResource.php',
            'app/Filament/Resources/System/TelegramBotResource/Pages/ManageTelegramBots.php',
            'app/Filament/Resources/System/TelegramBotBindResource.php',
            'app/Filament/Resources/System/TelegramBotBindResource/Pages/ManageTelegramBotBinds.php',
            'app/Filament/Resources/System/TelegramBotUpdateResource.php',
            'app/Filament/Resources/System/TelegramBotUpdateResource/Pages/ManageTelegramBotUpdates.php',
            'public/telegrambot.php',
        ];

        foreach ($files as $file) {
            $source = $stubsPath . '/' . $file;
            $target = base_path($file);
            if (!is_file($source)) {
                continue;
            }

            if (is_file($target) && sha1_file($target) !== sha1_file($source)) {
                copy($target, $target . '.telegram-bot.bak');
            }

            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0755, true);
            }

            copy($source, $target);
        }
    }

    private function ensureDependenciesAvailable(): void
    {
        if (!class_exists(\Telegram\Bot\Api::class)) {
            throw new \RuntimeException('Telegram Bot SDK is missing. Please install this plugin with composer require xiaomlove/nexusphp-telegram-bot so dependencies are installed.');
        }
    }

    private function seedDefaults(): void
    {
        $defaults = [
            'telegram_bot.announcement_sync_group_pin' => 'no',
            'telegram_bot.announcement_group_chat_id' => '',
            'telegram_bot.announcement_sync_channel' => 'no',
            'telegram_bot.announcement_channel_chat_id' => '',
        ];

        foreach ($defaults as $name => $value) {
            Setting::query()->firstOrCreate(
                ['name' => $name],
                ['value' => $value, 'autoload' => 'yes']
            );
        }
    }

    private function patchUserCpMenu(): void
    {
        $file = base_path('public/usercp.php');
        if (!is_file($file)) {
            return;
        }

        $content = file_get_contents($file);
        if ($content === false) {
            return;
        }

        $oldPatched = '	print ("<li><a href=\"telegrambot.php\">".nexus_trans(\'telegram-bot::messages.user.nav\')."</a></li>");';
        $newPatched = '	print ("<li><a href=\"telegrambot.php\">".\\NexusPlugin\\TelegramBot\\Support\\Translator::trans(\'user.nav\')."</a></li>");';
        if (str_contains($content, $oldPatched)) {
            copy($file, $file . '.telegram-bot-menu.bak');
            file_put_contents($file, str_replace($oldPatched, $newPatched, $content));
            return;
        }

        if (str_contains($content, 'telegrambot.php')) {
            return;
        }

        $old = '	print ("<li" . ($selected == "security" ? " class=selected" : "") . "><a href=\"?action=security\">".$lang_usercp[\'text_security_settings\']."</a></li>");';
        $new = $old . PHP_EOL . $newPatched;

        if (!str_contains($content, $old)) {
            return;
        }

        copy($file, $file . '.telegram-bot-menu.bak');
        file_put_contents($file, str_replace($old, $new, $content));
    }

    private function ensureServiceProviderRegistered(): void
    {
        $configPath = config_path('app.php');
        $provider = 'NexusPlugin\\TelegramBot\\ServiceProvider::class';

        if (!is_file($configPath)) {
            return;
        }

        $content = file_get_contents($configPath);
        if ($content === false || str_contains($content, $provider)) {
            return;
        }

        $needle = '        /*' . PHP_EOL . '         * Package Service Providers...';
        $replacement = "        {$provider}," . PHP_EOL . PHP_EOL . $needle;
        $updated = str_replace($needle, $replacement, $content);
        if ($updated !== $content) {
            file_put_contents($configPath, $updated);
        }
    }

    private function clearCaches(): void
    {
        try {
            Artisan::call('optimize:clear');
        } catch (\Throwable) {
            //
        }
    }
}
