<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 13.05.2018
 * Time: 0:02
 */

namespace console\migrations;


use yii\db\Connection;
use yii\db\Migration;

class AbstractMigration extends Migration
{

    /** @var Connection */
    public $remote_db;
    public $tableOptions = null;
    public $exception = [];

    public function __construct(array $config = [])
    {
        parent::__construct($config);
        $this->remote_db = \Yii::$app->params['remote_db'] ?? null;
    }

    public function init()
    {
        parent::init();

        if ($this->db->driverName === 'mysql') {
            // http://stackoverflow.com/questions/766809/whats-the-difference-between-utf8-general-ci-and-utf8-unicode-ci
            $this->tableOptions = 'CHARACTER SET utf8 COLLATE utf8_unicode_ci ENGINE=InnoDB';
        }
    }

    /**
     * @param $sql
     * @return array
     */
    public function getRemoteRows($sql)
    {
        if (!$this->remote_db instanceof Connection) {
            throw new \RuntimeException('Legacy import requires an explicitly configured remote_db connection.');
        }
        return $this->remote_db->createCommand($sql)->queryAll();
    }

    public function exceptionRow(string $field, $val)
    {
        if(!empty($this->exception[$field]) && in_array($val, $this->exception[$field])){
            return true;
        }
        return false;
    }
}
