<?php

namespace Gismatul\SupportChat\Settings;

use Gismatul\SupportChat\Enums\FieldMode;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Admin-editable settings, stored in the support_chat_settings table, with defaults from config.
 * Secrets (Telegram token, webhook secret) are deliberately NOT stored here: they stay in the environment.
 */
class ChatSettings
{
    public const TABLE = 'support_chat_settings';

    public const EMAIL_FIELD = 'email_field';

    public const PHONE_FIELD = 'phone_field';

    public const AUTO_CLOSE_HOURS = 'auto_close_hours';

    public const DELETE_AFTER_DAYS = 'delete_after_days';

    public const KEYS = [self::EMAIL_FIELD, self::PHONE_FIELD, self::AUTO_CLOSE_HOURS, self::DELETE_AFTER_DAYS];

    /** @var array<string, string|null>|null Loaded once per instance (registered as a scoped singleton). */
    protected ?array $stored = null;

    public function __construct(protected ConnectionInterface $db)
    {
    }

    public function emailField(): FieldMode
    {
        return $this->fieldMode(self::EMAIL_FIELD);
    }

    public function phoneField(): FieldMode
    {
        return $this->fieldMode(self::PHONE_FIELD);
    }

    public function autoCloseHours(): int
    {
        return max(0, (int) $this->get(self::AUTO_CLOSE_HOURS));
    }

    public function deleteAfterDays(): int
    {
        return max(0, (int) $this->get(self::DELETE_AFTER_DAYS));
    }

    /**
     * @return array{email_field: string, phone_field: string, auto_close_hours: int, delete_after_days: int}
     */
    public function all(): array
    {
        return [
            self::EMAIL_FIELD => $this->emailField()->value,
            self::PHONE_FIELD => $this->phoneField()->value,
            self::AUTO_CLOSE_HOURS => $this->autoCloseHours(),
            self::DELETE_AFTER_DAYS => $this->deleteAfterDays(),
        ];
    }

    /**
     * @param  array<string, mixed>  $values  Validated values; unknown keys are ignored.
     */
    public function save(array $values): void
    {
        $now = Carbon::now();

        foreach (array_intersect_key($values, array_flip(self::KEYS)) as $key => $value) {
            $this->db->table(self::TABLE)->updateOrInsert(
                ['key' => $key],
                ['value' => (string) $value, 'updated_at' => $now]
            );
        }

        $this->stored = null;
    }

    protected function fieldMode(string $key): FieldMode
    {
        // Unknown values fall back to the original behaviour: optional.
        return FieldMode::tryFrom((string) $this->get($key)) ?? FieldMode::Optional;
    }

    protected function get(string $key): mixed
    {
        $stored = $this->stored();

        return array_key_exists($key, $stored) ? $stored[$key] : config('support-chat.settings.' . $key);
    }

    /**
     * @return array<string, string|null>
     */
    protected function stored(): array
    {
        if ($this->stored !== null) {
            return $this->stored;
        }

        try {
            return $this->stored = $this->db->table(self::TABLE)->pluck('value', 'key')->all();
        } catch (Throwable) {
            // Table not migrated yet: use config defaults (and try again next time).
            return [];
        }
    }
}
