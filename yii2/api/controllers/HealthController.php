<?php

namespace api\controllers;

use Yii;
use yii\rest\Controller;

class HealthController extends Controller
{
    public function actionIndex()
    {
        Yii::$app->response->headers->set('Cache-Control', 'no-store');
        try {
            $environment = getenv('APP_ENVIRONMENT');
            if (!in_array($environment, ['production', 'test'], true)) throw new \RuntimeException('missing environment');
            $revision = trim((string) @file_get_contents(Yii::$app->runtimePath . '/deploy-version.txt'));
            if (!preg_match('/^[a-f0-9]{40}$/D', $revision)) throw new \RuntimeException('missing revision');
            foreach (['{{%user_presence}}' => 'last_seen_at', '{{%forum_comment_gift}}' => 'comment_id'] as $table => $column) {
                $schema = Yii::$app->db->getTableSchema($table, true);
                if (!$schema || !$schema->getColumn($column)) throw new \RuntimeException('missing schema');
            }
            return ['status' => 'ok', 'revision' => $revision, 'portalListsVersion' => 1, 'environment' => $environment];
        } catch (\Throwable $error) {
            Yii::$app->response->statusCode = 503;
            return ['status' => 'unavailable'];
        }
    }
}
