<?php
namespace common\modules\world\models\domain;

use common\modules\world\modules\economy\value\Money;
use common\modules\world\support\GameError;
use yii\db\Connection;
use yii\db\Query;

/** Successful-operation journal; shared owner/catalog locks serialize all writers. */
final class EconomyQuota
{
    public static function state(Connection $db, int $user, string $kind, ?int $now = null): ?array
    {
        $revision = EconomyRuntime::current($db); if (!$revision) return null;
        $profile = $revision['profile']; $now = $now ?? time();
        if (!in_array($kind, ['crafting_hourly', 'extraction_daily', 'sales_daily'], true)) throw new \InvalidArgumentException('Unknown economy quota.');
        $clock = \common\modules\world\models\WorldTime::class;
        $period = $clock::dayWindow($db, $now);
        $start = $kind === 'crafting_hourly' ? $clock::realAt($db, $clock::gameAt($db, $now) - 3600) : $period['start'];
        $rows = (new Query())->from('world_economy_usage')->where(['world_id' => EconomyRuntime::world($db), 'user_id' => $user, 'kind' => $kind])
            ->andWhere(['>', 'created_at', $kind === 'crafting_hourly' ? $start : $start - 1])->all($db);
        $used = 0; $gross = Money::parse('0');
        foreach ($rows as $row) { $used += (int)$row['quantity']; $gross = $gross->add(Money::parse((string)$row['gross_cr'])); }
        $maximum = $kind === 'sales_daily' ? $profile['limits'][$kind]['operations'] : $profile['limits'][$kind]['maximum'];
        if ($kind === 'extraction_daily') $maximum += HousingBenefits::extractionBonus($db, $user, $profile);
        return ['kind' => $kind, 'maximum' => $maximum, 'used' => $used, 'remaining' => max(0, $maximum - $used),
            'gross_cr' => $gross->decimal(), 'gross_remaining_cr' => $kind === 'sales_daily' ? ($gross->compare(Money::parse($profile['limits'][$kind]['gross_cr'])) >= 0 ? '0.0000' : Money::parse($profile['limits'][$kind]['gross_cr'])->subtract($gross)->decimal()) : null,
            'restores_at' => $kind === 'crafting_hourly' ? ($rows ? $clock::realAt($db, $clock::gameAt($db, min(array_column($rows, 'created_at'))) + 3600) : $now) : $period['end'],
            'server_time' => $now, 'profile_version' => $revision['version']];
    }
    public static function consume(Connection $db, int $user, string $kind, int $quantity, string $operation, string $gross = '0.0000'): void
    {
        $revision = EconomyRuntime::current($db); if (!$revision) return;
        if (!$db->getTransaction() || $quantity < 1) throw new \LogicException('Quota requires the successful command transaction.');
        $old = (new Query())->from('world_economy_usage')->where(['operation_id' => $operation, 'kind' => $kind])->one($db);
        if ($old) {
            if ((int)$old['user_id'] !== $user || (int)$old['quantity'] !== $quantity || Money::parse((string)$old['gross_cr'])->compare(Money::parse($gross)) !== 0) throw new \LogicException('Quota source reused.');
            return;
        }
        $state = self::state($db, $user, $kind); $amount = Money::parse($gross);
        if ($amount->isNegative()) throw new \InvalidArgumentException('Negative sales.');
        if ($state['remaining'] < $quantity || ($kind === 'sales_daily' && Money::parse($state['gross_remaining_cr'])->compare($amount) < 0))
            throw new GameError('ECONOMY_QUOTA_EXHAUSTED', 'Лимит действий исчерпан. Дождитесь его восстановления.', 409, $state);
        $db->createCommand()->insert('world_economy_usage', ['world_id' => EconomyRuntime::world($db), 'user_id' => $user, 'kind' => $kind,
            'quantity' => $quantity, 'gross_cr' => $amount->decimal(), 'operation_id' => $operation, 'profile_id' => $revision['id'], 'created_at' => time()])->execute();
    }
}
