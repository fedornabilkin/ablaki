<?php
namespace common\modules\world\models;

use common\modules\world\models\domain\WorldFlags;
use common\modules\world\support\CanonicalJson;
use common\modules\world\support\CommandBus;
use common\modules\world\support\CommandPipeline;
use common\modules\world\support\CommandRequest;
use common\modules\world\support\GameError;
use Yii;

/** Direct-command adapter: preserves the v2 fingerprint and response contract. */
class Command extends Record
{
    public static function tableName()
    {
        return 'game_command';
    }

    public static function run(
        int $user,
        string $key,
        string $type,
        array $payload,
        callable $handler,
        bool $administrative = false
    ): array {
        if (!preg_match('/^[A-Za-z0-9_-]{16,80}$/D', $key)) {
            throw new GameError('INVALID_REQUEST_KEY', 'Нужен уникальный ключ команды (16–80 символов).', 422);
        }
        $command = new CommandRequest(
            $user,
            $key,
            $type,
            $payload,
            hash('sha256', CanonicalJson::encode([$type, $payload])),
            CommandRequest::DIRECT,
            $administrative
        );
        $db = self::getDb();
        $flags = new WorldFlags($db, Yii::$app->getModule('world'));

        return (new CommandPipeline($db, $flags))->run($command, function (string $operation) use ($handler, $payload, $user, $type, $db, $flags) {
            $result = $handler($operation) + ['operation_id' => $operation];
            $result['changed_node_ids'] = self::changedNodes($result, $payload);
            if ($result['changed_node_ids']) {
                (new CommandBus($db, $flags))->emit($operation, $user, 'world.node.changed', [
                    'node_ids' => $result['changed_node_ids'],
                    'command' => $type,
                ]);
            }
            return $result;
        });
    }

    private static function changedNodes(array $result, array $payload): array
    {
        $changed = $result['changed_node_ids'] ?? [];
        foreach ([
            $result['node']['id'] ?? null,
            $result['build']['node_id'] ?? null,
            $payload['node_id'] ?? null,
        ] as $id) {
            if ($id !== null) {
                $changed[] = (int)$id;
            }
        }
        return array_values(array_unique($changed));
    }
}