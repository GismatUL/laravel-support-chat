@props(['locale' => null])
{{--
    Usage: <x-support-chat::widget /> before </body>.
    The config is printed as inert JSON (never executed) and the script is external, so this works
    with a strict Content-Security-Policy. Theme it from your own CSS: .sc-chat { --sc-chat-primary: #0f766e; }
--}}
@if (config('support-chat.enabled') && app(\Gmammadov\SupportChat\Telegram\TelegramClient::class)->isConfigured())
    @php
        $supportChatVersion = \Gmammadov\SupportChat\Http\Controllers\Api\AssetController::version();
        $supportChatConfig = app(\Gmammadov\SupportChat\Support\WidgetConfig::class)->toArray(request(), $locale);
    @endphp
    <link rel="stylesheet" href="{{ route('support-chat.api.assets', ['file' => 'support-chat.css', 'v' => $supportChatVersion]) }}" data-support-chat-css>
    <script type="application/json" id="support-chat-config">@json($supportChatConfig)</script>
    <script src="{{ route('support-chat.api.assets', ['file' => 'support-chat.js', 'v' => $supportChatVersion]) }}" defer></script>
@endif
