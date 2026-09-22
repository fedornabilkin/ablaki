<?php

namespace common\modules\forum\services;

use yii\db\Connection;
use yii\db\Query;

/** The paired debit history is the durable source of amounts on legacy schemas. */
class CommentGiftSchema
{
    public static function hasAmount(Connection $db): bool
    {
        $schema = $db->getTableSchema('forum_comment_gift');
        return $schema !== null && isset($schema->columns['amount']);
    }

    public static function sent(Connection $db, int $userId): int
    {
        if ($db->getTableSchema('forum_comment_gift') === null) return 0;
        $query = (new Query())->from('forum_comment_gift')->where(['user_id' => $userId]);
        if (self::hasAmount($db)) return (int)$query->sum('amount', $db);
        return (int)(new Query())->from(['gift' => 'forum_comment_gift'])
            ->where(['gift.user_id' => $userId])->sum(self::amountExpression($db), $db);
    }

    public static function amountExpression(Connection $db, bool $historyOnly = false): \yii\db\Expression
    {
        if (!$historyOnly && self::hasAmount($db)) return new \yii\db\Expression('[[gift.amount]]');
        $prefix = $db->quoteValue('Благодарность за сообщение №');
        $comment = $db->driverName === 'sqlite' ? $prefix . ' || [[gift.comment_id]]' : 'CONCAT(' . $prefix . ', [[gift.comment_id]])';
        return new \yii\db\Expression('(SELECT COALESCE(-SUM([[gift_history.credit_up]]), 1) FROM {{%history_balance}} [[gift_history]]'
            . ' WHERE [[gift_history.user_id]] = [[gift.user_id]] AND [[gift_history.type]] = ' . $db->quoteValue('forum_gift')
            . ' AND [[gift_history.created_at]] = [[gift.created_at]] AND [[gift_history.comment]] = ' . $comment
            . ' AND [[gift_history.credit_up]] < 0)');
    }

    public static function amount(Connection $db, int $commentId, int $donorId): int
    {
        return (int)(new Query())->select(self::amountExpression($db))->from(['gift' => 'forum_comment_gift'])
            ->where(['gift.comment_id' => $commentId, 'gift.user_id' => $donorId])->scalar($db);
    }
}
