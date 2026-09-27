<?php
namespace common\modules\world\service;

use common\modules\economy\service\FinanceReadSnapshot;
use common\services\game\GameError;
use common\services\game\Locks;
use yii\db\Connection;
use yii\db\Expression;
use yii\db\Query;

/** Private inbox. Event payloads and financial values never leak into public node caches. */
class WorldNotifications
{
    public const EVENTS = [
        'world.construction.finished' => ['construction', 'Строительство завершено', 'Постройка готова к использованию.'],
        'world.crop.harvested' => ['cultivation', 'Урожай собран', 'Урожай помещён в инвентарь.'],
        'production.completed' => ['production', 'Производство завершено', 'Заказ завершён. Результат доступен в карточке производства.'],
        'npc.training.finished' => ['npc', 'Обучение завершено', 'Работник закончил обучение.'],
        'world.quest.claimed' => ['quest', 'Награда получена', 'Награда за задание выдана.'],
        'world.profession.level-up' => ['progression', 'Уровень повышен', 'Доступны условия нового уровня профессии.'],
    ];
    private $db;
    private $flags;
    public function __construct(Connection $db, WorldFlags $flags) { $this->db = $db; $this->flags = $flags; }
    public function consume(array $payload, array $event): void
    {
        if (!$this->db->getTransaction()) throw new \LogicException('Notification delivery requires the inbox transaction.');
        $this->flags->requireFlag('world_read');
        if ($event['user_id'] === null || !isset(self::EVENTS[$event['event_type']])) return;
        list($type, $title, $body) = self::EVENTS[$event['event_type']];
        $user = (int)$event['user_id'];
        $preference = (new Query())->from('notification_preference')->where(['user_id' => $user, 'notification_type' => $type, 'channel' => 'in_app'])->one($this->db);
        if ($preference && !(bool)$preference['enabled']) return;
        $identity = ['user_id' => $user, 'source_event_id' => $event['id'], 'notification_type' => $type];
        if ((new Query())->from('game_notification')->where($identity)->exists($this->db)) return;
        // A notification survives ownership changes; avoid persisting a private node link.
        $this->db->createCommand()->insert('game_notification', $identity + ['title' => $title, 'body' => $body, 'created_at' => time()])->execute();
    }
    public function listing(int $user, int $page, string $search, string $status, string $type): array
    {
        $types = array_values(array_unique(array_column(self::EVENTS, 0)));
        if ($page < 1 || $page > 1000000 || mb_strlen($search, 'UTF-8') > 120 || !in_array($status, ['all', 'read', 'unread'], true) || ($type !== '' && !in_array($type, $types, true))) throw new GameError('INVALID_FILTER', 'Некорректный фильтр уведомлений.', 422);
        return (new FinanceReadSnapshot($this->db))->run(function () use ($user, $page, $search, $status, $type) {
            $this->flags->requireFlag('world_read');
            $query = (new Query())->from('game_notification')->where(['user_id' => $user]);
            if ($status === 'unread') $query->andWhere(['read_at' => null]);
            if ($status === 'read') $query->andWhere(['not', ['read_at' => null]]);
            if ($type !== '') $query->andWhere(['notification_type' => $type]);
            if ($search !== '') $query->andWhere(['or', ['like', 'title', $search], ['like', 'body', $search]]);
            $total = (int)(clone $query)->count('*', $this->db); $items = [];
            foreach ($query->orderBy(['id' => SORT_DESC])->offset(($page - 1) * 20)->limit(20)->all($this->db) as $row) $items[] = ['id' => (int)$row['id'], 'type' => $row['notification_type'], 'title' => $row['title'], 'body' => $row['body'], 'created_at' => (int)$row['created_at'], 'read_at' => $row['read_at'] === null ? null : (int)$row['read_at']];
            return ['items' => $items, 'unread_count' => (int)(new Query())->from('game_notification')->where(['user_id' => $user, 'read_at' => null])->count('*', $this->db), '_meta' => ['totalCount' => $total, 'pageCount' => (int)ceil($total / 20), 'currentPage' => $page, 'perPage' => 20], 'server_time' => time()];
        });
    }
    public function read(int $user, int $id): array
    {
        $this->flags->requireFlag('world_read');
        return $this->db->transaction(function () use ($user, $id) {
            (new Locks($this->db))->owners([$user]);
            $row = (new Query())->from('game_notification')->where(['id' => $id, 'user_id' => $user])->one($this->db);
            if (!$row) throw new GameError('NOTIFICATION_NOT_FOUND', 'Уведомление не найдено.', 404);
            $at = $row['read_at'] === null ? time() : (int)$row['read_at'];
            if ($row['read_at'] === null && $this->db->createCommand()->update('game_notification', ['read_at' => $at], ['id' => $id, 'user_id' => $user, 'read_at' => null])->execute() !== 1) throw new \RuntimeException('Notification read update failed.');
            return ['id' => $id, 'read_at' => $at];
        });
    }
    public function preferences(int $user): array
    {
        $this->flags->requireFlag('world_read'); $items = [];
        foreach (array_values(array_unique(array_column(self::EVENTS, 0))) as $type) {
            $row = (new Query())->from('notification_preference')->where(['user_id' => $user, 'notification_type' => $type, 'channel' => 'in_app'])->one($this->db);
            $items[] = ['type' => $type, 'channel' => 'in_app', 'enabled' => $row ? (bool)$row['enabled'] : true, 'quiet_start_minute' => $row && $row['quiet_start_minute'] !== null ? (int)$row['quiet_start_minute'] : null, 'quiet_end_minute' => $row && $row['quiet_end_minute'] !== null ? (int)$row['quiet_end_minute'] : null, 'timezone' => $row ? $row['timezone'] : 'Europe/Moscow', 'revision' => $row ? (int)$row['revision'] : 0];
        }
        return ['items' => $items, 'quiet_hours_behavior' => 'stored_for_future_push_channels;in_app_messages_remain_visible'];
    }
    public function configure(int $user, array $input): array
    {
        $type = $input['type'] ?? null; $start = $input['quiet_start_minute'] ?? null; $end = $input['quiet_end_minute'] ?? null;
        if (!in_array($type, array_values(array_unique(array_column(self::EVENTS, 0))), true) || ($input['channel'] ?? null) !== 'in_app' || !is_bool($input['enabled'] ?? null)
            || !is_int($input['expected_revision'] ?? null) || $input['expected_revision'] < 0 || !is_string($input['timezone'] ?? null) || !in_array($input['timezone'], \DateTimeZone::listIdentifiers(), true)
            || (($start !== null || $end !== null) && (!is_int($start) || !is_int($end) || $start < 0 || $end < 0 || $start > 1439 || $end > 1439 || $start === $end))) throw new GameError('INVALID_NOTIFICATION_PREFERENCE', 'Проверьте тип уведомления, часовой пояс и время тишины.', 422);
        $this->flags->requireFlag('world_read');
        return $this->db->transaction(function () use ($user, $input, $type, $start, $end) {
            (new Locks($this->db))->owners([$user]);
            $where = ['user_id' => $user, 'notification_type' => $type, 'channel' => 'in_app'];
            $old = (new Query())->from('notification_preference')->where($where)->one($this->db);
            $values = ['enabled' => $input['enabled'], 'quiet_start_minute' => $start, 'quiet_end_minute' => $end, 'timezone' => $input['timezone']];
            $same = $old && (bool)$old['enabled'] === $values['enabled'] && $old['timezone'] === $values['timezone'] && ($old['quiet_start_minute'] === null ? null : (int)$old['quiet_start_minute']) === $start && ($old['quiet_end_minute'] === null ? null : (int)$old['quiet_end_minute']) === $end;
            if ($same) return $this->preferences($user);
            if (($old ? (int)$old['revision'] : 0) !== $input['expected_revision']) throw new GameError('REVISION_CHANGED', 'Настройки изменились. Обновите страницу.');
            if ($old) {
                if ($this->db->createCommand()->update('notification_preference', $values + ['revision' => new Expression('[[revision]]+1')], $where)->execute() !== 1) throw new \RuntimeException('Notification preference update failed.');
            } else $this->db->createCommand()->insert('notification_preference', $where + $values)->execute();
            return $this->preferences($user);
        });
    }
}
