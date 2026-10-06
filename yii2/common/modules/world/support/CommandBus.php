<?php
namespace common\modules\world\support;

use common\modules\world\models\domain\WorldFlags;
use yii\db\Connection;
use yii\db\Query;

/** New commands only. The legacy craft command table and fingerprints stay intact. */
class CommandBus
{
    private $db;
    private $flags;
    private $locks;
    public function __construct(Connection $db, WorldFlags $flags) { $this->db = $db; $this->flags = $flags; $this->locks = new Locks($db); }
    private function begin(int $user): void
    {
        if (!$this->locks->row('craft_meta', ['id' => 1])) throw new GameError('SCHEMA_NOT_READY', 'Каталог ещё не подготовлен.', 503);
        $this->locks->owners([$user]);
        if (!$this->locks->row('world_registry', ['id' => 1])) throw new GameError('SCHEMA_NOT_READY', 'Мир ещё не подготовлен.', 503);
    }
    public function preview(int $user, string $type, array $payload, callable $prepare): array
    {
        $this->flags->requireFlag('world_write');
        return $this->db->transaction(function () use ($user, $type, $payload, $prepare) {
            $this->begin($user); $this->flags->requireFlag('world_write');
            \common\modules\world\modules\craft\models\domain\StorageMaintenance::writable($this->db);
            $this->rateLimit($user, 'game_quote', 60);
            $prepared = $prepare($payload);
            $revisions = $prepared['revisions']; $terms = $prepared['terms'];
            $quote = bin2hex(random_bytes(16)); $expires = time() + 300;
            $this->db->createCommand()->insert('game_quote', ['id' => $quote, 'user_id' => $user, 'command_type' => $type,
                'payload_json' => CanonicalJson::encode($payload), 'terms_json' => CanonicalJson::encode($terms), 'revisions_json' => CanonicalJson::encode($revisions),
                'expires_at' => $expires, 'created_at' => time()])->execute();
            return ['quote_id' => $quote, 'expires_at' => $expires, 'expected_revisions' => (object)$revisions, 'terms' => $terms, 'server_time' => time()];
        });
    }
    public function execute(int $user, string $key, string $type, array $payload, string $quoteId, array $expected, callable $handler): array
    {
        if (!$this->flags->capabilities()['schema_ready']) throw new GameError('SCHEMA_NOT_READY', 'Мир ещё не подготовлен.', 503);
        if (!preg_match('/^[A-Za-z0-9_-]{16,80}$/D', $key) || !preg_match('/^[a-f0-9]{32}$/D', $quoteId)) throw new GameError('INVALID_COMMAND', 'Некорректный ключ команды или расчёт.', 422);
        $fingerprint = hash('sha256', CanonicalJson::encode(['type' => $type, 'version' => 1, 'payload' => $payload, 'quote_id' => $quoteId, 'expected_revisions' => $expected]));
        return $this->db->transaction(function () use ($user, $key, $type, $payload, $quoteId, $expected, $handler, $fingerprint) {
            $this->begin($user);
            $previous = (new Query())->from('game_command')->where(['user_id' => $user, 'request_key' => $key])->one($this->db);
            if ($previous) {
                if (!hash_equals($previous['fingerprint'], $fingerprint)) throw new GameError('REQUEST_KEY_REUSED', 'Этот ключ уже использован для другой команды.');
                return json_decode($previous['result_json'], true, 512, JSON_THROW_ON_ERROR);
            }
            // Completed commands remain replayable when new writes are disabled.
            \common\modules\world\modules\craft\models\domain\StorageMaintenance::writable($this->db);
            $this->flags->requireFlag('world_write');
            $this->rateLimit($user, 'game_command', 60);
            $quote = (new Query())->from('game_quote')->where(['id' => $quoteId, 'user_id' => $user])->one($this->db);
            if (!$quote || $quote['command_type'] !== $type || $quote['payload_json'] !== CanonicalJson::encode($payload)) throw new GameError('QUOTE_MISMATCH', 'Расчёт не соответствует действию.');
            if ($quote['operation_id'] !== null) throw new GameError('QUOTE_ALREADY_USED', 'Этот расчёт уже использован.');
            if ((int)$quote['expires_at'] <= time()) throw new GameError('QUOTE_EXPIRED', 'Срок расчёта истёк. Обновите его.');
            if (CanonicalJson::encode($expected) !== $quote['revisions_json']) throw new GameError('REVISION_CHANGED', 'Подтвердите актуальные условия.');
            $this->checkRevisions($expected);
            $operation = bin2hex(random_bytes(16));
            $this->db->createCommand()->insert('game_operation', ['id' => $operation, 'user_id' => $user, 'type' => $type, 'created_at' => time()])->execute();
            $result = $handler($payload, json_decode($quote['terms_json'], true, 512, JSON_THROW_ON_ERROR), $operation);
            $result['operation_id'] = $operation; $result['request_key'] = $key; $result['server_time'] = time(); $result['contract_version'] = 1;
            $encoded = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $this->db->createCommand()->insert('game_command', ['user_id' => $user, 'request_key' => $key, 'command_type' => $type, 'contract_version' => 1,
                'fingerprint' => $fingerprint, 'operation_id' => $operation, 'result_json' => $encoded, 'created_at' => time()])->execute();
            $this->db->createCommand()->update('game_quote', ['operation_id' => $operation], ['id' => $quoteId])->execute();
            return $result;
        });
    }
    private function checkRevisions(array $expected): void
    {
        foreach ($expected as $target => $revision) {
            if (!is_int($revision) || $revision < 1) throw new GameError('INVALID_REVISION', 'Некорректная версия.', 422);
            if ($target === 'catalog') $row = (new Query())->from('craft_meta')->where(['id' => 1])->one($this->db);
            elseif ($target === 'registry') {
                $row = (new Query())->from('world_registry')->where(['id' => 1])->one($this->db);
                $row['revision'] = $row['content_revision'];
            } elseif (preg_match('/^(node|storage|inventory|instance|account|actor):([1-9][0-9]*)$/D', $target, $match)) {
                $tables = ['node' => 'world_node', 'storage' => 'craft_storage', 'inventory' => 'craft_inventory', 'instance' => 'craft_equipment_instance', 'account' => 'economy_account', 'actor' => 'game_actor'];
                $row = (new Query())->from($tables[$match[1]])->where(['id' => (int)$match[2]])->one($this->db);
            }
            else throw new GameError('INVALID_REVISION', 'Неизвестный объект версии.', 422);
            if (!$row || (int)$row['revision'] !== $revision) throw new GameError('REVISION_CHANGED', 'Состояние изменилось. Повторите предварительный расчёт.');
        }
    }
    private function rateLimit(int $user, string $table, int $maximum): void
    {
        $count = (new Query())->from($table)->where(['user_id' => $user])->andWhere(['>=', 'created_at', time() - 60])->count('*', $this->db);
        if ((int)$count >= $maximum) throw new GameError('COMMAND_RATE_LIMIT', 'Слишком много действий. Подождите минуту.', 429);
    }
    public function emit(string $operation, ?int $user, string $type, array $payload): string
    {
        if (!$this->db->getTransaction()) throw new \LogicException('Events must be committed with the command.');
        $id = bin2hex(random_bytes(16));
        $this->db->createCommand()->insert('game_outbox', ['id' => $id, 'operation_id' => $operation, 'user_id' => $user, 'event_type' => $type,
            'event_version' => 1, 'payload_json' => CanonicalJson::encode($payload), 'created_at' => time()])->execute();
        if (in_array($type, \common\modules\world\modules\progression\models\domain\WorldProgression::SOURCES, true)) (new \common\modules\world\modules\progression\models\domain\ProgressionAwards($this->db))->dispatch($id, $operation);
        return $id;
    }
}
