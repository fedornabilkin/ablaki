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
            $revision = trim((string) @file_get_contents(Yii::$app->runtimePath . '/deploy-version.txt'));
            if (!preg_match('/^[a-f0-9]{40}$/D', $revision)) throw new \RuntimeException('missing revision');
            foreach (['{{%user_presence}}' => 'last_seen_at', '{{%forum_comment_gift}}' => 'comment_id'] as $table => $column) {
                $schema = Yii::$app->db->getTableSchema($table, true);
                if (!$schema || !$schema->getColumn($column)) throw new \RuntimeException('missing schema');
            }
            return ['status' => 'ok', 'revision' => $revision, 'portalListsVersion' => 1];
        } catch (\Throwable $error) {
            Yii::$app->response->statusCode = 503;
            return ['status' => 'unavailable'];
        }
    }
}
