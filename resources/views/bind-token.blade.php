@if($message)
    <p>{{ $message }}</p>
@else
    <p>{{ nexus_trans('telegram-bot::messages.bind_page_intro') }}</p>
    <p><code>/bind {{ $bind->bind_token }}</code></p>
    @if($url)
        <p><a href="{{ $url }}" target="_blank" rel="noopener">{{ $url }}</a></p>
    @endif
@endif
