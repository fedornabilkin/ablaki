<?php

namespace common\modules\games\service;

use common\services\user\CreditLedger;
use Yii;
use yii\db\Query;
use yii\web\ConflictHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

class GameCancellationService
{
    /** Locks each game before refunding its reserved stake, in the same transaction. */
    public function cancel(string $kind, int $userId, ?int $id = null): array
    {
        if (!in_array($kind, ['orel', 'saper', 'duel', 'five'], true) || $userId < 1) {
            throw new \InvalidArgumentException('Invalid game cancellation.');
        }
        $table = 'game_' . $kind;
        return Yii::$app->db->transaction(function () use ($kind, $userId, $id, $table): array {
            $db = Yii::$app->db;
            $ledger = new CreditLedger($db);
            $condition = ['user_id' => $userId, 'user_gamer' => 0];
            if ($kind === 'five') $condition['status'] = 'free';
            $ids = $id === null
                ? (new Query())->select('id')->from($table)->where($condition)->orderBy(['id' => SORT_ASC])->column($db)
                : [$id];
            $deleted = 0;
            $amount = 0.0;
            $games = [];
            foreach ($ids as $gameId) {
                $game = $ledger->lock($table, ['id' => $gameId]);
                if (!$game || (int)$game['user_id'] !== $userId || (int)$game['user_gamer'] !== 0
                    || ($kind === 'five' && $game['status'] !== 'free')) {
                    if ($id !== null) {
                        if (!$game) throw new NotFoundHttpException('Игра не найдена.');
                        if ((int)$game['user_id'] !== $userId) throw new ForbiddenHttpException('Можно удалить только свою игру.');
                        throw new ConflictHttpException('Игра уже начата.');
                    }
                    continue;
                }
                $games[] = $game;
            }
            // Acquire all game locks before the account lock, matching participation lock order.
            foreach ($games as $game) {
                $gameId = $game['id'];
                if (!is_numeric($game['kon']) || !is_finite((float)$game['kon']) || $game['kon'] <= 0) {
                    throw new \RuntimeException('Invalid reserved stake.');
                }
                if ($kind === 'five') $db->createCommand()->delete('game_five_hod', ['game_five_id' => $gameId])->execute();
                if ($db->createCommand()->delete($table, array_merge($condition, ['id' => $gameId]))->execute() !== 1) {
                    throw new \RuntimeException('Game cancellation failed.');
                }
                $method = $kind === 'saper' ? 'changeBalance' : 'change';
                $ledger->$method($userId, (float)$game['kon'], $table, 'Delete game #' . $gameId);
                $deleted++;
                $amount += (float)$game['kon'];
            }
            return ['deleted' => $deleted, 'refunded' => round($amount, 2)];
        });
    }
}
