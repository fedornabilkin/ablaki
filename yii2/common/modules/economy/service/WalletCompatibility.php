<?php
namespace common\modules\economy\service;

use yii\db\Connection;
use yii\db\Query;

/** Offline checks for funds still reserved by old modules. Never rewrites or cancels a position. */
class WalletCompatibility
{
    private $db;
    public function __construct(Connection $db) { $this->db = $db; }
    public function report(): array
    {
        $result = ['read_only' => true, 'sources' => [], 'table_issues' => [], 'requires_decision' => 0];
        $required = ['user', 'persone', 'history_balance', 'history_rating', \common\models\Commission::tableName(), 'game_orel', 'game_saper',
            'game_duel', 'game_five', 'game_five_hod', 'credit_exchange', 'credit_transfer', 'forum_comment', 'forum_comment_gift'];
        $tables = $this->db->schema->getTableNames();
        foreach ($required as $table) if (!in_array($table, $tables, true)) $result['table_issues'][] = ['table' => $table, 'reason' => 'missing-table'];
        foreach ($tables as $table) {
            if (!in_array($table, array_merge($required, ['advertising']), true) && !preg_match('/^(craft_|world_|game_|economy_)/D', $table)) continue;
            if ($this->db->driverName === 'mysql') {
                $engine = $this->db->createCommand('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table', [':table' => $table])->queryScalar();
                if (strtolower((string)$engine) !== 'innodb') $result['table_issues'][] = ['table' => $table, 'reason' => 'requires-innodb'];
            }
        }
        foreach (['game_orel', 'game_duel', 'game_five', 'credit_exchange', 'credit_transfer', 'advertising'] as $table) {
            if (!in_array($table, $tables, true)) continue;
            $field = strpos($table, 'game_') === 0 ? 'kon' : ($table === 'credit_transfer' ? 'amount' : 'credit');
            $query = (new Query())->select(['id', $field])->from($table)->orderBy(['id' => SORT_ASC]);
            if ($table === 'game_five') $query->where(['status' => ['free', 'play']]);
            elseif (strpos($table, 'game_') === 0) $query->where(['or', ['user_gamer' => null], ['user_gamer' => 0]]);
            elseif ($table !== 'advertising') $query->where(['or', ['user_buyer' => null], ['user_buyer' => 0]]);
            else $query->where(['!=', 'credit', 0]);
            $count = 0; $invalid = 0; $samples = [];
            foreach ($query->each(500, $this->db) as $row) {
                $count++;
                try {
                    if (strpos($table, 'game_') === 0) LegacyCreditPolicy::game($row[$field]);
                    elseif ($table === 'credit_exchange') LegacyCreditPolicy::exchange($row[$field]);
                    else LegacyCreditPolicy::amount($row[$field]);
                } catch (\yii\web\UnprocessableEntityHttpException $e) {
                    $invalid++;
                    if (count($samples) < 30) $samples[] = ['id' => (int)$row['id'], 'reason' => 'amount-or-commission-precision'];
                }
            }
            $result['sources'][$table] = ['rows' => $count, 'requires_decision' => $invalid, 'samples' => $samples];
            $result['requires_decision'] += $invalid;
        }
        if (in_array('persone', $tables, true)) {
            $duplicates = (new Query())->select('user_id')->from('persone')->groupBy('user_id')->having('COUNT(*) > 1')->limit(30)->column($this->db);
            if ($duplicates) $result['table_issues'][] = ['table' => 'persone', 'reason' => 'duplicate-owner', 'user_ids' => $duplicates];
            if (in_array('user', $tables, true)) {
                $orphans = (new Query())->select('p.id')->from(['p' => 'persone'])->leftJoin(['u' => 'user'], '[[u.id]]=[[p.user_id]]')
                    ->where(['u.id' => null])->limit(30)->column($this->db);
                if ($orphans) $result['table_issues'][] = ['table' => 'persone', 'reason' => 'missing-owner', 'row_ids' => $orphans];
            }
        }
        $result['requires_decision'] += count($result['table_issues']);
        return $result;
    }
    public function requireCompatible(): void
    {
        if ($this->report()['requires_decision'] !== 0) throw new \RuntimeException('Legacy funds or transaction tables require a decision. Read world-audit/credits; no positions were changed.');
    }
}
