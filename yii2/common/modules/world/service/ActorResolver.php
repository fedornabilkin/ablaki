<?php
namespace common\modules\world\service;

use yii\db\Connection;
use yii\db\Query;

class ActorResolver
{
    private $db;
    public function __construct(Connection $db) { $this->db = $db; }
    /** Called after locking the real user; NPCs never create a user or personal wallet. */
    public function player(int $user): array
    {
        if (!$this->db->getTransaction()) throw new \LogicException('Actor creation requires the command transaction.');
        $row = (new Query())->from('game_actor')->where(['user_id' => $user])->one($this->db);
        if (!$row) {
            $this->db->createCommand()->insert('game_actor', ['kind' => 'player', 'user_id' => $user])->execute();
            $row = (new Query())->from('game_actor')->where(['user_id' => $user])->one($this->db);
        }
        if ($row['kind'] !== 'player') throw new \LogicException('User points to a non-player actor.');
        return $row;
    }
}
