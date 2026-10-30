<?php
namespace common\modules\world\support;

/** Per-command data. Never shared with another request or middleware chain. */
final class CommandRequest
{
    public const QUOTED = 1;
    public const DIRECT = 2;

    public readonly string $operationId;

    public function __construct(
        public readonly int $userId,
        public readonly string $key,
        public readonly string $type,
        public readonly array $payload,
        public readonly string $fingerprint,
        public readonly int $version,
        public readonly bool $administrative = false
    ) {
        if (!in_array($version, [self::QUOTED, self::DIRECT], true)) {
            throw new \InvalidArgumentException('Unsupported command contract.');
        }
        $this->operationId = bin2hex(random_bytes(16));
    }
}
