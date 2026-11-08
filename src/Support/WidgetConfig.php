<?php

namespace Gmammadov\SupportChat\Support;

use Gmammadov\SupportChat\Settings\ChatSettings;
use Gmammadov\SupportChat\SupportChat;
use Illuminate\Http\Request;

/**
 * Public configuration for the widget script. Contains no secrets.
 */
class WidgetConfig
{
    public function __construct(protected ChatSettings $settings)
    {
    }

    public function toArray(Request $request, ?string $locale = null): array
    {
        $locale = $this->locale($locale);
        $customer = SupportChat::customer($request);

        return [
            'startUrl' => route('support-chat.api.conversations.start'),
            'messagesUrl' => route('support-chat.api.messages.index', ['conversation' => '__UUID__']),
            'pollInterval' => max(1, (int) config('support-chat.poll_interval', 3)) * 1000,
            'maxLength' => (int) config('support-chat.message_max_length', 2000),
            'fields' => [
                'email' => $this->settings->emailField()->value,
                'phone' => $this->settings->phoneField()->value,
            ],
            'prefill' => [
                'name' => $customer['name'] ?? null,
                'email' => $customer['email'] ?? null,
                'phone' => $customer['phone'] ?? null,
            ],
            'locale' => $locale,
            'i18n' => trans('support-chat::support-chat.widget', [], $locale),
        ];
    }

    protected function locale(?string $requested): string
    {
        $translator = app('translator');

        if ($requested && preg_match('/^[a-z]{2,3}([_-][A-Za-z]{2,4})?$/', $requested)
            && $translator->hasForLocale('support-chat::support-chat.widget.title', $requested)) {
            return $requested;
        }

        return app()->getLocale();
    }
}
