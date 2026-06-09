<?php

namespace NexusPlugin\TelegramBot\Support;

use Illuminate\Support\Facades\App;

class Translator
{
    private static array $messages = [];

    public static function trans(string $key, array $replace = [], ?string $locale = null): string
    {
        $locale = self::normalizeLocale($locale);
        $fullKey = 'telegram-bot::messages.' . $key;
        $translated = nexus_trans($fullKey, $replace, $locale);
        if ($translated !== $fullKey) {
            return $translated;
        }

        $translated = self::getFromLocalFile($locale, $key) ?? self::getFromLocalFile('en', $key) ?? $fullKey;
        foreach ($replace as $name => $value) {
            $translated = str_replace(':' . $name, (string) $value, $translated);
        }

        return $translated;
    }

    private static function normalizeLocale(?string $locale): string
    {
        if (function_exists('get_langfolder_cookie')) {
            $locale ??= get_langfolder_cookie(true);
        }

        $locale ??= App::getLocale();

        return match ($locale) {
            'chs' => 'zh_CN',
            'cht' => 'zh_TW',
            default => $locale ?: 'en',
        };
    }

    private static function getFromLocalFile(string $locale, string $key): ?string
    {
        $messages = self::loadMessages($locale);
        foreach (explode('.', $key) as $segment) {
            if (!is_array($messages) || !array_key_exists($segment, $messages)) {
                return null;
            }
            $messages = $messages[$segment];
        }

        return is_string($messages) ? $messages : null;
    }

    private static function loadMessages(string $locale): array
    {
        if (!array_key_exists($locale, self::$messages)) {
            $path = __DIR__ . '/../../resources/lang/' . $locale . '/messages.php';
            self::$messages[$locale] = file_exists($path) ? require $path : [];
        }

        return self::$messages[$locale];
    }
}
