<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ nexus_trans('telegram-bot::messages.bind_page_title') }}</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 720px; margin: 48px auto; padding: 0 18px; line-height: 1.6; }
        code { display: block; padding: 12px; background: #f4f4f5; border-radius: 6px; word-break: break-all; }
        a.button { display: inline-block; margin-top: 16px; padding: 10px 14px; background: #2563eb; color: #fff; border-radius: 6px; text-decoration: none; }
    </style>
</head>
<body>
<h1>{{ nexus_trans('telegram-bot::messages.bind_page_title') }}</h1>
@if($message)
    <p>{{ $message }}</p>
@else
    <p>{{ nexus_trans('telegram-bot::messages.bind_page_intro') }}</p>
    <code>/bind {{ $bind->bind_token }}</code>
    @if($url)
        <p><a class="button" href="{{ $url }}" target="_blank" rel="noopener">{{ nexus_trans('telegram-bot::messages.fields.open_telegram') }}</a></p>
    @endif
@endif
</body>
</html>
