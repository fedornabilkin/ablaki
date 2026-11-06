<?php
namespace common\modules\world\models\domain;

use common\modules\world\modules\craft\models\domain\CraftStorage;
use common\modules\world\support\CanonicalJson;
use common\modules\world\support\GameError;
use common\modules\world\support\Locks;
use yii\db\Connection;
use yii\db\Query;

/** One lifetime gift per site account AND verified Telegram identity, independent of world resets. */
final class TelegramReward
{
    public const CODE = 'telegram-link';
    // Version 1 offers are immutable. Approximately 120 Cr in catalogue sale prices;
    // these are items, never a promise to exchange the gift for credits.
    public const PACKS = [
        'seeds' => ['world-carrot-seed' => 60],
        'rations' => ['world-travel-ration' => 10],
        'broth' => ['world-vegetable-broth' => 7],
        'supplies' => ['classic-log' => 30, 'classic-water' => 60],
    ];

    public function __construct(private readonly Connection $db) {}

    public static function pack(int $user): string
    {
        // A stable pseudo-random draw: GET stays read-only; refresh, login and unlink
        // cannot reroll the offer. Never base the draw on a rotating auth key.
        return array_keys(self::PACKS)[hexdec(substr(hash('sha256', 'telegram-gift-v1:' . $user), 0, 6)) % count(self::PACKS)];
    }

    public function ready(): bool
    {
        return $this->db->schema->getTableSchema('world_telegram_reward') !== null
            && $this->db->schema->getTableSchema('auth_identity') !== null
            && $this->db->schema->getTableSchema('craft_item') !== null;
    }

    /** Must precede the user lock, matching Telegram link/unlink's identity -> user order. */
    public function lockIdentity(int $user): ?array
    {
        if (!$this->ready()) throw new GameError('REWARD_UNAVAILABLE', 'Награда пока не подготовлена.', 503);
        // Lock the existing identity by PK, not a missing unique user_id range:
        // MySQL gap locks could otherwise deadlock against a concurrent new link.
        $id = (new Query())->select('id')->from('auth_identity')->where(['provider' => 'telegram', 'user_id' => $user])->scalar($this->db);
        if (!$id) return null;
        $identity = (new Locks($this->db))->row('auth_identity', ['id' => $id]);
        return $identity && (int)$identity['user_id'] === $user ? $identity : null;
    }

    public function state(int $user): ?array
    {
        if (!$this->ready()) return null;
        $claim = (new Query())->from('world_telegram_reward')->where(['user_id' => $user])->one($this->db);
        $pack = $claim['pack_code'] ?? self::pack($user);
        $quantities = $claim ? json_decode($claim['items_json'], true, 16, JSON_THROW_ON_ERROR) : self::PACKS[$pack];
        $items = [];
        foreach ((new Query())->from('craft_item')->where(['code' => array_keys($quantities), 'active' => 1])->orderBy('id')->all($this->db) as $item) {
            $items[] = ['code' => $item['code'], 'name' => trim($item['name']), 'quantity' => $quantities[$item['code']]];
        }
        if (count($items) !== count($quantities)) return null;
        $identity = (new Query())->from('auth_identity')->where(['provider' => 'telegram', 'user_id' => $user])->one($this->db);
        $used = $identity && (new Query())->from('world_telegram_reward')->where(['identity_hash' => self::identityHash($identity)])->exists($this->db);
        $reason = $claim ? null : (!$identity ? 'Подключите Telegram в профиле.' : ($used ? 'Этот Telegram уже получил награду на другом аккаунте.' : null));
        return ['code' => self::CODE, 'pack' => $pack, 'items' => $items, 'linked' => (bool)$identity,
            'claimed' => (bool)$claim, 'can_claim' => !$claim && $identity && !$used,
            'blocked_reason' => $reason];
    }

    public function claim(int $user, string $operation): array
    {
        if (!$this->db->getTransaction()) throw new \LogicException('Reward needs command locks.');
        $identity = $this->lockIdentity($user); // Already acquired before the user by CommandTransaction.
        if (!$identity) throw new GameError('TELEGRAM_NOT_LINKED', 'Сначала подключите Telegram в профиле.', 409);
        $state = $this->state($user);
        if (!$state || !$state['can_claim']) throw new GameError('REWARD_NOT_AVAILABLE', $state['blocked_reason'] ?? 'Награда уже получена или временно недоступна.', 409);
        $store = new CraftStorage($this->db);
        $store->operationId = $operation;
        $quantities = self::PACKS[$state['pack']];
        foreach ($quantities as $code => $quantity) {
            $item = (new Query())->from('craft_item')->where(['code' => $code, 'active' => 1])->one($this->db);
            if (!$item) throw new GameError('REWARD_UNAVAILABLE', 'Предмет награды временно недоступен.', 409);
            $store->move($user, $item, $quantity);
        }
        // Capacity failure or any subsequent write rolls back ALL items and the receipt.
        if ($this->db->createCommand()->insert('world_telegram_reward', [
            'user_id' => $user, 'identity_hash' => self::identityHash($identity), 'pack_code' => $state['pack'],
            'items_json' => CanonicalJson::encode($quantities), 'operation_id' => $operation, 'created_at' => time(),
        ])->execute() !== 1) throw new \RuntimeException('Telegram reward receipt failed.');
        if ($this->db->createCommand()->insert('craft_event', ['user_id' => $user, 'action' => 'telegram_reward',
            'quantity' => array_sum($quantities), 'credit_change' => 0, 'created_at' => time()])->execute() !== 1) {
            throw new \RuntimeException('Telegram reward history failed.');
        }
        return ['telegram_reward' => $this->state($user), 'message' => 'Подарок за Telegram добавлен в рюкзак.'];
    }

    private static function identityHash(array $identity): string
    {
        return hash('sha256', 'telegram:' . $identity['subject']);
    }
}
