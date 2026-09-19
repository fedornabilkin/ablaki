<?php

namespace common\modules\forum\services;

use common\services\user\CreditLedger;
use yii\db\Connection;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

class CommentEditService
{
    private $db;
    public function __construct(Connection $db) { $this->db = $db; }

    public function edit(int $id, int $userId, string $text): void
    {
        $this->db->transaction(function () use ($id, $userId, $text) {
            $row = (new CreditLedger($this->db))->lock('forum_comment', ['id' => $id]);
            if (!$row || (int)$row['active'] !== 1) throw new NotFoundHttpException();
            $now = time();
            if ((int)$row['user_id'] !== $userId || (int)$row['created_at'] > $now || $now - (int)$row['created_at'] > 600) {
                throw new ForbiddenHttpException('Редактировать своё сообщение можно в течение 10 минут после публикации.');
            }
            if ((string)$row['comment'] === $text) return;
            if ($this->db->createCommand()->update('forum_comment', ['comment' => $text], ['id' => $id])->execute() !== 1) {
                throw new \RuntimeException('Could not save comment.');
            }
        });
    }
}
