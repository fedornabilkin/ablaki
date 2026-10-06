<?php
namespace common\modules\world\models;

use common\modules\world\models\domain\WorldFlags;
use common\modules\world\support\CanonicalJson;
use common\modules\world\support\GameError;
use common\modules\world\support\Locks;
use Yii;

/** Idempotent commands without a mandatory quote round trip. Terms are supplied explicitly. */
class Command extends Record
{
    public static function tableName() { return 'game_command'; }
    public static function run(int $user, string $key, string $type, array $payload, callable $handler): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{16,80}$/D', $key)) throw new GameError('INVALID_REQUEST_KEY', 'Нужен уникальный ключ команды (16–80 символов).', 422);
        $fingerprint = hash('sha256', CanonicalJson::encode([$type, $payload]));
        return self::getDb()->transaction(function () use ($user, $key, $type, $payload, $handler, $fingerprint) {
            $db = self::getDb(); $locks = new Locks($db);
            $locks->row('craft_meta', ['id' => 1]);
            $owners = [$user];
            if (isset($payload['build_id'])) $owners = array_merge($owners, BuildWork::participants((int)$payload['build_id']));
            $locks->owners($owners, false); $locks->owners([$user]); $locks->row('world_registry', ['id' => 1]);
            $previous = self::findOne(['user_id' => $user, 'request_key' => $key]);
            if ($previous) {
                if (!hash_equals($previous->fingerprint, $fingerprint)) throw new GameError('REQUEST_KEY_REUSED', 'Ключ уже использован для другого действия.', 409);
                return json_decode($previous->result_json, true);
            }
            (new WorldFlags($db, Yii::$app->getModule('world')))->requireFlags(['world_write', 'storage_v2']);
            $operation = bin2hex(random_bytes(16));
            $db->createCommand()->insert('game_operation', ['id' => $operation, 'user_id' => $user, 'type' => $type, 'created_at' => time()])->execute();
            $result = $handler($operation) + ['operation_id' => $operation];
            $record = new self(); $record->setAttributes(['user_id' => $user, 'request_key' => $key, 'command_type' => $type, 'contract_version' => 2,
                'fingerprint' => $fingerprint, 'operation_id' => $operation, 'result_json' => CanonicalJson::encode($result), 'created_at' => time()], false);
            if (!$record->save(false)) throw new \RuntimeException('Command write failed.');
            return json_decode($record->result_json, true);
        });
    }
}
