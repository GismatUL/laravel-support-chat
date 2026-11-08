<?php

namespace Gmammadov\SupportChat\Http\Requests;

use Gmammadov\SupportChat\Enums\FieldMode;
use Gmammadov\SupportChat\Settings\ChatSettings;
use Gmammadov\SupportChat\Support\TextSanitizer;
use Illuminate\Foundation\Http\FormRequest;

class StartConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $settings = $this->settings();

        $this->merge([
            'name' => TextSanitizer::clean($this->input('name')),
            // A field the admin has hidden is ignored even if a client sends it.
            'email' => $settings->emailField() === FieldMode::Hidden ? null : (TextSanitizer::clean($this->input('email')) ?: null),
            'phone' => $settings->phoneField() === FieldMode::Hidden ? null : (TextSanitizer::clean($this->input('phone')) ?: null),
            'message' => TextSanitizer::clean($this->input('message')) ?: null,
        ]);
    }

    public function rules(): array
    {
        $settings = $this->settings();

        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => [$this->presence($settings->emailField()), 'string', 'email', 'max:120'],
            'phone' => [$this->presence($settings->phoneField()), 'string', 'regex:/^\+?[0-9\s()\-]{5,30}$/'],
            'message' => ['nullable', 'string', 'max:' . (int) config('support-chat.message_max_length', 2000)],
            'client_message_id' => ['nullable', 'uuid'],
            // Honeypot: hidden in the widget, only bots fill it.
            'website' => ['prohibited'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => trans('support-chat::support-chat.widget.name'),
            'email' => trans('support-chat::support-chat.widget.email'),
            'phone' => trans('support-chat::support-chat.widget.phone'),
            'message' => trans('support-chat::support-chat.widget.message'),
        ];
    }

    protected function presence(FieldMode $mode): string
    {
        return $mode === FieldMode::Required ? 'required' : 'nullable';
    }

    protected function settings(): ChatSettings
    {
        return $this->container->make(ChatSettings::class);
    }
}
