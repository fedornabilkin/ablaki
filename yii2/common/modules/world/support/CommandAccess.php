<?php
namespace common\modules\world\support;

use common\modules\world\models\domain\EconomyRuntime;
use common\modules\world\models\domain\WorldFlags;
use common\modules\world\modules\craft\models\domain\StorageMaintenance;
use yii\db\Connection;

/** Maintenance may stop new writes, never accepted receipts. */
final class CommandAccess implements CommandMiddleware
{
    public function __construct(
        private readonly Connection $db,
        private readonly WorldFlags $flags
    ) {
    }

    public function handle(CommandRequest $command, callable $next): array
    {
        EconomyRuntime::requireAccess($this->db, $command->userId, $command->administrative);
        if ($command->version === CommandRequest::QUOTED) {
            StorageMaintenance::writable($this->db);
            $this->flags->requireFlag('world_write');
        } else {
            $this->flags->requireFlags(['world_write', 'storage_v2']);
        }
        return $next($command);
    }
}
