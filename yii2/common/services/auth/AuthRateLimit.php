<?php
namespace common\services\auth;

use yii\db\Connection;
use yii\db\Expression;
use yii\db\Query;
use yii\web\TooManyRequestsHttpException;

/** Database counters work across PHP workers and do not rely on a per-process cache. */
final class AuthRateLimit
{
    public function __construct(private Connection $db, private ?\Closure $clock = null) {}

    public function hit(string $scope, string $key, int $limit, int $window = 60): void
    {
        $now = $this->clock ? ($this->clock)() : time();
        $id = hash('sha256', $scope . ':' . $key . ':' . intdiv($now, $window));
        // PostgreSQL exposes both the stored row and EXCLUDED in ON CONFLICT.
        $this->db->createCommand()->upsert('{{%auth_rate_limit}}', [
            'id' => $id, 'hits' => 1, 'expires_at' => $now + $window * 2,
        ], ['hits' => new Expression('{{%auth_rate_limit}}.[[hits]] + 1')])->execute();
        $hits = (new Query())->select('hits')->from('{{%auth_rate_limit}}')->where(['id' => $id])->scalar($this->db);
        if ((int)$hits > $limit) throw new TooManyRequestsHttpException('Слишком много попыток. Подождите минуту.');
    }
}
