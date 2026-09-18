<?php
// Run through the actual login form after the standard authentication regressions.
$db->createCommand('CREATE TABLE users (id INTEGER PRIMARY KEY, login TEXT, salt TEXT, password TEXT)')->execute();
$db->schema->refresh();
$db->createCommand()->insert('users', ['id' => 1, 'login' => 'FixtureUser', 'salt' => 'fixture-salt', 'password' => md5('legacy-password' . md5('fixture-salt'))])->execute();
$legacyLogin = function (string $password) use ($app) {
    $app->user->setIdentity(null);
    $app->response->setStatusCode(200);
    $app->request->setBodyParams(['login' => 'FixtureUser', 'password' => $password]);
    return $app->runAction('site/login');
};
$db->createCommand()->update('user', ['password_hash' => '', 'blocked_at' => 1], ['id' => 1])->execute();
$response = $legacyLogin('legacy-password');
authResponseCheck($app->user->isGuest && !isset($response['token']) && $db->createCommand('SELECT password_hash FROM user WHERE id=1')->queryScalar() === '', 'legacy password does not bypass blocked account or change its hash');
$db->createCommand()->update('user', ['blocked_at' => null], ['id' => 1])->execute();
$response = $legacyLogin('incorrect');
authResponseCheck($app->user->isGuest && !isset($response['token']), 'wrong legacy password rejected');
$response = $legacyLogin('legacy-password');
authResponseCheck($app->user->id === 1 && !empty($response['token']) && password_verify('legacy-password', $db->createCommand('SELECT password_hash FROM user WHERE id=1')->queryScalar()), 'legacy login upgrades password and completes existing session/token flow');
authResponseCheck($db->createCommand('SELECT last_login_at FROM user WHERE id=1')->queryScalar() > 0, 'legacy login updates normal activity');
$db->createCommand()->update('user', ['password_hash' => password_hash('new-password', PASSWORD_BCRYPT)], ['id' => 1])->execute();
authResponseCheck(!isset($legacyLogin('legacy-password')['token']) && $app->user->isGuest, 'old password cannot override a reset password');
authResponseCheck(!empty($legacyLogin('new-password')['token']), 'current login still works after reset');
