<?php

namespace common\modules\forum\services;

use DomainException;
use RuntimeException;
use yii\db\Connection;
use yii\db\Expression;
use yii\db\Query;

/** A donor can thank a message once. Both accounts and histories commit together. */
class CommentGiftService
{
    private $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    public function give(int $commentId, int $donorId): array
    {
        if ($commentId < 1 || $donorId < 1) throw new DomainException('Invalid donor or message.');
        return $this->db->transaction(function () use ($commentId, $donorId) {
            // Lock the message first, then accounts in stable order across all gifts.
            $comment = $this->lock('forum_comment', ['id' => $commentId], 'active');
            if (!$comment || (int)$comment['active'] !== 1) throw new DomainException('Message unavailable.');
            $recipientId = (int)$comment['user_id'];
            if ($recipientId === $donorId) throw new DomainException('Cannot give credit to your own message.');
            $ids = [$donorId, $recipientId];
            sort($ids, SORT_NUMERIC);
            $people = [];
            foreach ($ids as $userId) {
                $people[$userId] = $this->lock('persone', ['user_id' => $userId], 'credit');
                if (!$people[$userId] || !is_numeric($people[$userId]['credit']) || !is_finite((float)$people[$userId]['credit'])) {
                    throw new DomainException('Account unavailable.');
                }
            }
            $already = (new Query())->from('forum_comment_gift')
                ->where(['comment_id' => $commentId, 'user_id' => $donorId])->exists($this->db);
            if (!$already) {
                // The predicate prevents overdraft even if an older balance writer ignores locks.
                $changed = $this->db->createCommand()->update('persone', [
                    'credit' => new Expression('[[credit]] - 1'),
                ], ['and', ['user_id' => $donorId], ['>=', 'credit', 1]])->execute();
                if ($changed !== 1) throw new DomainException('Not enough credit.');
                if ($this->db->createCommand()->update('persone', [
                    'credit' => new Expression('[[credit]] + 1'),
                ], ['user_id' => $recipientId])->execute() !== 1) {
                    throw new RuntimeException('Could not credit recipient.');
                }
                $now = time();
                if ($this->db->createCommand()->insert('forum_comment_gift', [
                    'comment_id' => $commentId, 'user_id' => $donorId,
                    'recipient_id' => $recipientId, 'created_at' => $now,
                ])->execute() !== 1) throw new RuntimeException('Could not record gift.');
                foreach ([$donorId => -1, $recipientId => 1] as $userId => $change) {
                    $person = (new Query())->from('persone')->where(['user_id' => $userId])->one($this->db);
                    if ($this->db->createCommand()->insert('history_balance', [
                        'user_id' => $userId, 'balance' => $person['balance'], 'credit' => $person['credit'],
                        'balance_up' => 0, 'credit_up' => $change, 'type' => 'forum_gift',
                        'comment' => 'Благодарность за сообщение №' . $commentId, 'created_at' => $now,
                    ])->execute() !== 1) throw new RuntimeException('Could not record gift history.');
                }
            }
            return [
                'commentId' => $commentId, 'alreadyGiven' => $already, 'giftedByMe' => true,
                'giftCount' => (int)(new Query())->from('forum_comment_gift')->where(['comment_id' => $commentId])->count('*', $this->db),
                'credit' => (float)(new Query())->from('persone')->select('credit')->where(['user_id' => $donorId])->scalar($this->db),
            ];
        });
    }

    private function lock(string $table, array $condition, string $counter)
    {
        $query = (new Query())->from($table)->where($condition);
        if ($this->db->driverName === 'sqlite') {
            // Raw SQL avoids schema introspection taking a read lock before the write lock.
            $column = key($condition);
            $quotedCounter = $this->db->quoteColumnName($counter);
            $this->db->createCommand('UPDATE ' . $this->db->quoteTableName($table) . ' SET ' .
                $quotedCounter . '=' . $quotedCounter . ' WHERE ' . $this->db->quoteColumnName($column) . '=:key',
                [':key' => $condition[$column]])->execute();
            return $query->one($this->db);
        }
        if (!in_array($this->db->driverName, ['pgsql', 'mysql'], true)) throw new RuntimeException('Unsupported database.');
        $command = $query->createCommand($this->db);
        return $this->db->createCommand($command->sql . ' FOR UPDATE', $command->params)->queryOne();
    }
}
