<?php

namespace common\modules\games\models;

/** Legacy PostgreSQL CHAR(50) status columns return space-padded values. */
trait LegacyGameStatusTrait
{
    public static function populateRecord($record, $row)
    {
        if (isset($row['status'])) $row['status'] = rtrim((string)$row['status']);
        parent::populateRecord($record, $row);
    }
}
