<?php
namespace common\modules\world\support;

use yii\db\Connection;
use yii\db\Query;

/** A replay skips all writes; a new receipt commits with its effects. */
final class CommandReplay implements CommandMiddleware
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function handle(CommandRequest $command, callable $next): array
    {
        $previous = (new Query())
            ->from('game_command')
            ->where(['user_id' => $command->userId, 'request_key' => $command->key])
            ->one($this->db);
        if ($previous) {
            if (!hash_equals($previous['fingerprint'], $command->fingerprint)) {
                $message = $command->version === CommandRequest::DIRECT
                    ? 'Ключ уже использован для другого действия.'
                    : 'Этот ключ уже использован для другой команды.';
                throw new GameError('REQUEST_KEY_REUSED', $message, 409);
            }
            return json_decode($previous['result_json'], true, 512, JSON_THROW_ON_ERROR);
        }

        $result = $next($command);
        $encoded = $command->version === CommandRequest::DIRECT
            ? CanonicalJson::encode($result)
            : json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $written = $this->db->createCommand()->insert('game_command', [
            'user_id' => $command->userId,
            'request_key' => $command->key,
            'command_type' => $command->type,
            'contract_version' => $command->version,
            'fingerprint' => $command->fingerprint,
            'operation_id' => $command->operationId,
            'result_json' => $encoded,
            'created_at' => time(),
        ])->execute();
        if ($written !== 1) {
            throw new \RuntimeException('Command write failed.');
        }

        return $command->version === CommandRequest::DIRECT
            ? json_decode($encoded, true, 512, JSON_THROW_ON_ERROR)
            : $result;
    }
}
