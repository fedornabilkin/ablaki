<?php

namespace common\services\user;

use dektrium\user\models\User;
use Yii;
use yii\db\Connection;
use yii\db\Query;

/** Compatibility with test/app/models/model_users.php; never supersedes a new password. */
class LegacyPasswordService
{
    public function verify(User $user, string $password): bool
    {
        // The original import leaves password_hash empty. Once upgraded/reset, only the new hash applies.
        if (trim((string)$user->password_hash) !== '') return false;
        $db = Yii::$app->params['remote_db'] ?? Yii::$app->db;
        if (!$db instanceof Connection) return false;
        try {
            $schema = $db->getTableSchema('users');
            if (!$schema || !isset($schema->columns['salt'], $schema->columns['password'], $schema->columns['login'])) return false;
            $legacy = (new Query())->select(['id', 'login', 'salt', 'password'])->from('users')
                ->where(['id' => $user->id, 'login' => $user->username])->one($db);
            return $legacy && is_string($legacy['salt']) && $legacy['salt'] !== ''
                && preg_match('/^[a-f0-9]{32}$/iD', (string)$legacy['password'])
                && hash_equals(strtolower($legacy['password']), md5($password . md5($legacy['salt'])));
        } catch (\yii\db\Exception $error) {
            Yii::warning('Legacy authentication source unavailable.', __METHOD__);
            return false;
        }
    }
}
