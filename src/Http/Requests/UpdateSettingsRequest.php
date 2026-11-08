<?php

namespace Gmammadov\SupportChat\Http\Requests;

use Gmammadov\SupportChat\Enums\FieldMode;
use Gmammadov\SupportChat\Settings\ChatSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route is protected by the supportChat.manage gate.
        return true;
    }

    public function rules(): array
    {
        $modes = Rule::enum(FieldMode::class);

        return [
            ChatSettings::EMAIL_FIELD => ['required', $modes],
            ChatSettings::PHONE_FIELD => ['required', $modes],
            ChatSettings::AUTO_CLOSE_HOURS => ['required', 'integer', 'min:0', 'max:8760'],
            ChatSettings::DELETE_AFTER_DAYS => ['required', 'integer', 'min:0', 'max:3650'],
        ];
    }

    public function attributes(): array
    {
        return collect(ChatSettings::KEYS)
            ->mapWithKeys(fn (string $key) => [$key => trans('support-chat::support-chat.settings.' . $key)])
            ->all();
    }
}
