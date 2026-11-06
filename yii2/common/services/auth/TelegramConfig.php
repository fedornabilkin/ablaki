<?php
namespace common\services\auth;

final class TelegramConfig
{
    public function __construct(
        public readonly bool $enabled,
        public readonly string $botUsername,
        public readonly string $apiKey,
        public readonly int $ttl = 300,
        public readonly array $botIps = [],
    ) {}

    public static function fromApplication(): self
    {
        $settings = (new TelegramSettings(\Yii::$app->db))->read();
        return new self(
            $settings['enabled'],
            $settings['bot_username'],
            (string)getenv('TELEGRAM_AUTH_API_KEY'),
            300,
            [],
        );
    }

    public function available(): bool
    {
        return $this->enabled && preg_match('/^[a-zA-Z0-9_]{2,29}bot$/iD', $this->botUsername)
            && strlen($this->apiKey) >= 32;
    }
}
