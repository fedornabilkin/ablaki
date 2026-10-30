<?php
namespace common\modules\world\support;

use common\modules\world\models\domain\EconomyRuntime;
use common\modules\world\models\domain\WorldFlags;
use common\modules\world\modules\craft\models\domain\StorageMaintenance;
use common\modules\world\modules\progression\models\domain\ProgressionAwards;
use common\modules\world\modules\progression\models\domain\WorldProgression;
use yii\db\Connection;
use yii\db\Query;

/** Quoted-command adapter. Legacy craft commands keep their own table and fingerprints. */
class CommandBus
{
    private const REVISION_TABLES = [
        'node' => 'world_node',
        'storage' => 'craft_storage',
        'inventory' => 'craft_inventory',
        'instance' => 'craft_equipment_instance',
        'account' => 'economy_account',
        'actor' => 'game_actor',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly WorldFlags $flags
    ) {
    }

    public function preview(int $user, string $type, array $payload, callable $prepare): array
    {
        $this->flags->requireFlag('world_write');
        $request = new CommandRequest($user, '', $type, $payload, '', CommandRequest::QUOTED);
        return (new CommandTransaction($this->db))->handle($request, function () use ($user, $type, $payload, $prepare) {
            $this->flags->requireFlag('world_write');
            EconomyRuntime::requireAccess($this->db, $user);
            StorageMaintenance::writable($this->db);
            $this->rateLimit($user, 'game_quote', 60);

            $prepared = $prepare($payload);
            $quote = bin2hex(random_bytes(16));
            $expires = time() + 300;
            $written = $this->db->createCommand()->insert('game_quote', [
                'id' => $quote,
                'user_id' => $user,
                'command_type' => $type,
                'payload_json' => CanonicalJson::encode($payload),
                'terms_json' => CanonicalJson::encode($prepared['terms']),
                'revisions_json' => CanonicalJson::encode($prepared['revisions']),
                'expires_at' => $expires,
                'created_at' => time(),
            ])->execute();
            if ($written !== 1) {
                throw new \RuntimeException('Quote write failed.');
            }
            return [
                'quote_id' => $quote,
                'expires_at' => $expires,
                'expected_revisions' => (object)$prepared['revisions'],
                'terms' => $prepared['terms'],
                'server_time' => time(),
            ];
        });
    }

    public function execute(
        int $user,
        string $key,
        string $type,
        array $payload,
        string $quoteId,
        array $expected,
        callable $handler
    ): array {
        if (!$this->flags->capabilities()['schema_ready']) {
            throw new GameError('SCHEMA_NOT_READY', 'Мир ещё не подготовлен.', 503);
        }
        if (!preg_match('/^[A-Za-z0-9_-]{16,80}$/D', $key) || !preg_match('/^[a-f0-9]{32}$/D', $quoteId)) {
            throw new GameError('INVALID_COMMAND', 'Некорректный ключ команды или расчёт.', 422);
        }
        $fingerprint = hash('sha256', CanonicalJson::encode([
            'type' => $type,
            'version' => 1,
            'payload' => $payload,
            'quote_id' => $quoteId,
            'expected_revisions' => $expected,
        ]));
        $request = new CommandRequest($user, $key, $type, $payload, $fingerprint, CommandRequest::QUOTED);

        return (new CommandPipeline($this->db, $this->flags))->run($request, function (string $operation) use ($user, $key, $type, $payload, $quoteId, $expected, $handler) {
            $this->rateLimit($user, 'game_command', 60);
            $terms = $this->quoteTerms($user, $type, $payload, $quoteId, $expected);
            $result = $handler($payload, $terms, $operation);
            $result['operation_id'] = $operation;
            $result['request_key'] = $key;
            $result['server_time'] = time();
            $result['contract_version'] = 1;

            $written = $this->db->createCommand()->update(
                'game_quote',
                ['operation_id' => $operation],
                ['id' => $quoteId, 'operation_id' => null]
            )->execute();
            if ($written !== 1) {
                throw new \RuntimeException('Quote consumption failed.');
            }
            return $result;
        });
    }

    private function quoteTerms(int $user, string $type, array $payload, string $quoteId, array $expected): array
    {
        $quote = (new Query())->from('game_quote')->where(['id' => $quoteId, 'user_id' => $user])->one($this->db);
        if (!$quote || $quote['command_type'] !== $type || $quote['payload_json'] !== CanonicalJson::encode($payload)) {
            throw new GameError('QUOTE_MISMATCH', 'Расчёт не соответствует действию.');
        }
        if ($quote['operation_id'] !== null) {
            throw new GameError('QUOTE_ALREADY_USED', 'Этот расчёт уже использован.');
        }
        if ((int)$quote['expires_at'] <= time()) {
            throw new GameError('QUOTE_EXPIRED', 'Срок расчёта истёк. Обновите его.');
        }
        if (CanonicalJson::encode($expected) !== $quote['revisions_json']) {
            throw new GameError('REVISION_CHANGED', 'Подтвердите актуальные условия.');
        }
        $this->checkRevisions($expected);
        return json_decode($quote['terms_json'], true, 512, JSON_THROW_ON_ERROR);
    }

    private function checkRevisions(array $expected): void
    {
        foreach ($expected as $target => $revision) {
            if (!is_int($revision) || $revision < 1) {
                throw new GameError('INVALID_REVISION', 'Некорректная версия.', 422);
            }
            if ($target === 'catalog') {
                $row = (new Query())->from('craft_meta')->where(['id' => 1])->one($this->db);
            } elseif ($target === 'registry') {
                $row = (new Query())->from('world_registry')->where(['id' => 1])->one($this->db);
                if ($row) {
                    $row['revision'] = $row['content_revision'];
                }
            } elseif (preg_match('/^(node|storage|inventory|instance|account|actor):([1-9][0-9]*)$/D', $target, $match)) {
                $row = (new Query())->from(self::REVISION_TABLES[$match[1]])->where(['id' => (int)$match[2]])->one($this->db);
            } else {
                throw new GameError('INVALID_REVISION', 'Неизвестный объект версии.', 422);
            }
            if (!$row || (int)$row['revision'] !== $revision) {
                throw new GameError('REVISION_CHANGED', 'Состояние изменилось. Повторите предварительный расчёт.');
            }
        }
    }

    private function rateLimit(int $user, string $table, int $maximum): void
    {
        $count = (new Query())
            ->from($table)
            ->where(['user_id' => $user])
            ->andWhere(['>=', 'created_at', time() - 60])
            ->count('*', $this->db);
        if ((int)$count >= $maximum) {
            throw new GameError('COMMAND_RATE_LIMIT', 'Слишком много действий. Подождите минуту.', 429);
        }
    }

    public function emit(string $operation, ?int $user, string $type, array $payload): string
    {
        if (!$this->db->getTransaction()) {
            throw new \LogicException('Events must be committed with the command.');
        }
        $id = bin2hex(random_bytes(16));
        $written = $this->db->createCommand()->insert('game_outbox', [
            'id' => $id,
            'operation_id' => $operation,
            'user_id' => $user,
            'event_type' => $type,
            'event_version' => 1,
            'payload_json' => CanonicalJson::encode($payload),
            'created_at' => time(),
        ])->execute();
        if ($written !== 1) {
            throw new \RuntimeException('Event write failed.');
        }
        if (in_array($type, WorldProgression::SOURCES, true)) {
            (new ProgressionAwards($this->db))->dispatch($id, $operation);
        }
        return $id;
    }
}