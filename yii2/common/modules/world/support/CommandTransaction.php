<?php
namespace common\modules\world\support;

use common\modules\world\models\BuildWork;
use common\modules\world\models\Node;
use yii\db\Connection;
use yii\db\Query;

/** Serializes retries and locks every possible payroll recipient before the registry. */
final class CommandTransaction implements CommandMiddleware
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function handle(CommandRequest $command, callable $next): array
    {
        return $this->db->transaction(function () use ($command, $next) {
            $locks = new Locks($this->db);
            $catalog = $locks->row('craft_meta', ['id' => 1]);
            if (!$catalog && $command->version === CommandRequest::QUOTED) {
                throw new GameError('SCHEMA_NOT_READY', 'Каталог ещё не подготовлен.', 503);
            }

            if ($command->version === CommandRequest::DIRECT) {
                $locks->owners($this->participants($command), false);
            }
            $locks->owners([$command->userId]);
            $registry = $locks->row('world_registry', ['id' => 1]);
            if (!$registry && $command->version === CommandRequest::QUOTED) {
                throw new GameError('SCHEMA_NOT_READY', 'Мир ещё не подготовлен.', 503);
            }

            return $next($command);
        });
    }

    private function participants(CommandRequest $command): array
    {
        $owners = [$command->userId];
        if (isset($command->payload['build_id'])) {
            $owners = array_merge($owners, BuildWork::participants((int)$command->payload['build_id']));
        }
        if (isset($command->payload['mining_node_id'])) {
            $nodeId = $command->payload['mining_node_id'];
            $owners[] = (int)Node::requireOne($nodeId)->owner_user_id;
            $workers = (new Query())
                ->select('user_id')
                ->from('world_source_work')
                ->where(['node_id' => $nodeId])
                ->andWhere(['not', ['active_key' => null]])
                ->column($this->db);
            $owners = array_merge($owners, $workers);
        }
        return $owners;
    }
}
