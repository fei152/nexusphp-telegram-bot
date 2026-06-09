@php
    $tabs = [
        'bots' => ['label' => nexus_trans('telegram-bot::messages.admin.bots_tab'), 'url' => \App\Filament\Resources\System\TelegramBotResource::getUrl()],
        'binds' => ['label' => nexus_trans('telegram-bot::messages.admin.binds_tab'), 'url' => \App\Filament\Resources\System\TelegramBotBindResource::getUrl()],
        'updates' => ['label' => nexus_trans('telegram-bot::messages.admin.updates_tab'), 'url' => \App\Filament\Resources\System\TelegramBotUpdateResource::getUrl()],
    ];
@endphp

<x-filament::tabs contained>
    @foreach ($tabs as $tabKey => $tab)
        <x-filament::tabs.item
            tag="a"
            :href="$tab['url']"
            :active="($active ?? 'bots') === $tabKey"
        >
            {{ $tab['label'] }}
        </x-filament::tabs.item>
    @endforeach
</x-filament::tabs>
