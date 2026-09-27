<?php
namespace backend\components;

use common\services\game\CanonicalJson;
use Yii;
use yii\web\HttpException;

/** POST confirmation is bound to the original route, user and immutable server-side payload. */
class WorldConfirmation
{
    public static function remember(string $route, array $record): array
    {
        $record['quote']['expected_revisions'] = (array)$record['quote']['expected_revisions'];
        $record += ['route' => $route, 'user_id' => (int)Yii::$app->user->id, 'key' => bin2hex(random_bytes(16))];
        $record['digest'] = hash('sha256', CanonicalJson::encode($record));
        $entries = Yii::$app->session->get('world.crud.confirmations', []);
        $entries[$record['quote']['quote_id']] = $record;
        Yii::$app->session->set('world.crud.confirmations', array_slice($entries, -20, null, true));
        return $record;
    }
    public static function submitted(string $route): ?array
    {
        $token = Yii::$app->request->post('quote_id');
        if ($token === null) return null;
        $entries = Yii::$app->session->get('world.crud.confirmations', []);
        $record = is_string($token) ? ($entries[$token] ?? null) : null;
        $digest = Yii::$app->request->post('digest');
        if (!$record || $record['route'] !== $route || $record['user_id'] !== (int)Yii::$app->user->id || !is_string($digest) || !hash_equals($record['digest'], $digest)) {
            throw new HttpException(409, 'Подтверждение недоступно. Повторите предварительный просмотр.');
        }
        return $record;
    }
}
