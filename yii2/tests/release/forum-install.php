<?php
// The real privacy installer only uses this disposable database.
define('YII_ENABLE_ERROR_HANDLER',false);
require dirname(__DIR__,2).'/vendor/autoload.php';
require dirname(__DIR__,2).'/vendor/yiisoft/yii2/Yii.php';
Yii::setAlias('@console',dirname(__DIR__,2).'/console');
error_reporting(E_ALL & ~E_DEPRECATED);
$app=new \yii\console\Application(['id'=>'forum-install-tests','basePath'=>dirname(__DIR__,2),
    'components'=>['db'=>['class'=>\yii\db\Connection::class,'dsn'=>'sqlite::memory:']]]);
$db=$app->db;
$db->createCommand()->createTable('forum_theme',['id'=>'pk','title'=>'string'])->execute();
$db->createCommand()->insert('forum_theme',['id'=>72,'title'=>'Существующая тема'])->execute();
$setup=new \console\controllers\ForumSetupController('forum-setup',$app);
function checkForumInstall($condition,$message){if(!$condition)throw new \RuntimeException($message);echo 'PASS '.$message.PHP_EOL;}
$old=getenv('FORUM_INSTALL');
try {
    putenv('FORUM_INSTALL');
    try {$setup->runAction('install');throw new \LogicException('Installer accepted missing context.');}
    catch (\RuntimeException $e) {checkForumInstall(strpos($e->getMessage(),'deployment context required')!==false,'forum installer requires an explicit deployment context');}
    checkForumInstall(!$db->schema->getTableSchema('forum_theme',true)->getColumn('is_private'),'rejected forum installation leaves schema unchanged');
    putenv('FORUM_INSTALL=confirmed-test-checkout');
    checkForumInstall($setup->runAction('install')===0,'test installer adds privacy migration');
    checkForumInstall((int)$db->createCommand('SELECT is_private FROM forum_theme WHERE id=72')->queryScalar()===0,'existing themes remain public');
    $db->createCommand()->update('forum_theme',['is_private'=>1],['id'=>72])->execute();
    putenv('FORUM_INSTALL=confirmed-production-checkout');
    checkForumInstall($setup->runAction('install')===0,'production installer supports repeated deployment');
    $theme=$db->createCommand('SELECT * FROM forum_theme WHERE id=72')->queryOne();
    checkForumInstall((int)$theme['is_private']===1&&$theme['title']==='Существующая тема','repeated installer preserves existing visibility and text');
    checkForumInstall((int)$db->createCommand('SELECT COUNT(*) FROM migration')->queryScalar()===2,'installer records only privacy migration and history base');
} finally {putenv($old===false?'FORUM_INSTALL':'FORUM_INSTALL='.$old);}
