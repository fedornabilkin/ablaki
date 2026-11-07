<?php
// Included after API regressions; use the same isolated users and real Dektrium model mapping.
Yii::setAlias('@backend', dirname(__DIR__, 2) . '/backend');
$backendConfig = require dirname(__DIR__, 2) . '/backend/config/main.php';
$module = $app->getModule('user');
$module->modelMap['LoginForm'] = $backendConfig['modules']['user']['modelMap']['LoginForm'];
(new \dektrium\user\Bootstrap())->bootstrap($app);
$module->debug = false;
$module->enableConfirmation = true;
$module->enableUnconfirmedLogin = false;
foreach ([false, true] as $legacy) {
    $password = $legacy ? 'legacy-password' : '  Modern&password  ';
    $hash = $legacy ? md5($password . md5('fixture-salt')) : password_hash($password, PASSWORD_BCRYPT);
    $db->createCommand()->update('user', ['password_hash'=>$hash, 'salt'=>$legacy?'fixture-salt':null, 'confirmed_at'=>1, 'blocked_at'=>null], ['id'=>1])->execute();
    $app->user->setIdentity(null);
    $app->request->setBodyParams(['login'=>'FixtureUser','password'=>$password]);
    authResponseCheck(!empty(authWithoutPasswordWrites($app, 'login')['token']), 'frontend accepts shared fixture credentials');
    foreach (['FixtureUser', 'fixture@example.invalid'] as $login) {
        $app->user->setIdentity(null);
        $form = Yii::createObject(\dektrium\user\models\LoginForm::class);
        authResponseCheck($form instanceof \backend\models\user\LoginForm, 'admin route resolves the configured shared password form');
        $form->login = $login; $form->password = $password;
        $db->pdo->exec("CREATE TRIGGER admin_no_password_write BEFORE UPDATE OF password_hash,salt ON user BEGIN SELECT RAISE(ABORT,'password must stay unchanged'); END");
        try {
            authResponseCheck($form->login() && (int)$app->user->id === 1, 'admin accepts the same '.($legacy?'legacy':'modern').' password by login or email');
            authResponseCheck($db->createCommand('SELECT password_hash FROM user WHERE id=1')->queryScalar() === $hash, 'admin login preserves the stored password');
        } finally { $db->pdo->exec('DROP TRIGGER admin_no_password_write'); }
    }
    foreach (['wrong', 'blocked', 'unconfirmed', 'missing'] as $case) {
        $db->createCommand()->update('user', ['blocked_at'=>$case==='blocked'?time():null, 'confirmed_at'=>$case==='unconfirmed'?null:1], ['id'=>1])->execute();
        $app->user->setIdentity(null);
        $form = Yii::createObject(\dektrium\user\models\LoginForm::class);
        $form->login = $case==='missing'?'MissingUser':'FixtureUser';
        $form->password = $case==='wrong'?'wrong-password':$password;
        authResponseCheck(!$form->login() && $app->user->isGuest, 'admin rejects '.$case.' credentials for '.($legacy?'legacy':'modern').' account');
    }
}
