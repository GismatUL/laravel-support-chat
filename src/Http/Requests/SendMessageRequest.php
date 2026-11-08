<?php

namespace Gmammadov\SupportChat\Http\Requests;

use Gmammadov\SupportChat\Support\TextSanitizer;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A chat message, from the customer widget or from an admin panel.
 * Authorisation happens before this request is resolved (conversation token or admin gate).
 */
class SendMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['message' => TextSanitizer::clean($this->input('message'))]);
    }

    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:' . (int) config('support-chat.message_max_length', 2000)],
            'client_message_id' => ['nullable', 'uuid'],
        ];
    }

    public function attributes(): array
    {
        return [
            'message' => trans('support-chat::support-chat.widget.message'),
        ];
    }
}
