<?php

/** FLOAT cannot retain the small diminishing awards. No historic rating is recalculated. */
class m261101_130000_world_rating_precision extends \common\modules\world\support\WorldMigration
{
    public function safeUp()
    {
        (new \common\services\user\LegacyRatingPrecision($this->db))->upgrade();
    }
}
