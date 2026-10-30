<?php
namespace common\modules\world\support;

use common\modules\world\models\domain\EconomyRuntime;
use common\modules\world\models\domain\WorldFlags;
use yii\db\Connection;

/** Domain changes, wallet history, events and the replay receipt share one transaction. */
final class CommandPipeline
{
    public function __construct(
        private readonly Connection $db,
        private readonly WorldFlags $flags
    ) {
    }

    /** @param callable(string): array $handler Receives the persisted operation ID. */
    public function run(CommandRequest $command, callable $handler): array
    {
        $next = function (CommandRequest $request) use ($handler): array {
            $written = $this->db->createCommand()->insert('game_operation', [
                'id' => $request->operationId,
                'user_id' => $request->userId,
                'type' => $request->type,
                'created_at' => time(),
            ] + EconomyRuntime::operationContext($this->db, $request->payload))->execute();
            if ($written !== 1) {
                throw new \RuntimeException('Operation write failed.');
            }
            return $handler($request->operationId);
        };

        $middleware = [
            new CommandTransaction($this->db),
            new CommandReplay($this->db),
            new CommandAccess($this->db, $this->flags),
        ];
        foreach (array_reverse($middleware) as $stage) {
            $following = $next;
            $next = static fn(CommandRequest $request): array => $stage->handle($request, $following);
        }
        return $next($command);
    }
}
