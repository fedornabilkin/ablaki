<?php
// Run through the actual login form after the standard authentication regressions.
$db->createCommand('ALTER TABLE user ADD COLUMN salt TEXT')->execute();
$db->schema->refresh();
$legacyHash = md5('legacy-password' . md5('fixture-salt'));
$legacyLogin = function (string $password) use ($app) {
    $app->user->setIdentity(null);
    $app->response->setStatusCode(200);
    $app->request->setBodyParams(['login' => 'FixtureUser', 'password' => $password]);
    return authWithoutCredentialWrites($app, 'login');
};
$db->createCommand()->update('user', ['password_hash' => $legacyHash, 'salt' => 'fixture-salt', 'blocked_at' => 1], ['id' => 1])->execute();
$response = $legacyLogin('legacy-password');
authResponseCheck($app->user->isGuest && !isset($response['token']) && $db->createCommand('SELECT password_hash FROM user WHERE id=1')->queryScalar() === $legacyHash, 'legacy password does not bypass blocked account or change its hash');
$db->createCommand()->update('user', ['blocked_at' => null], ['id' => 1])->execute();
$response = $legacyLogin('incorrect');
authResponseCheck($app->user->isGuest && !isset($response['token']), 'wrong legacy password rejected');
authResponseCheck($db->createCommand('SELECT password_hash FROM user WHERE id=1')->queryScalar() === $legacyHash, 'wrong legacy password leaves stored hash unchanged');
$response = $legacyLogin('legacy-password');
authResponseCheck($app->user->id === 1 && !empty($response['token']) && $db->createCommand('SELECT password_hash FROM user WHERE id=1')->queryScalar() === $legacyHash, 'legacy login completes session/token flow without replacing the hash');
authResponseCheck($db->createCommand('SELECT last_login_at FROM user WHERE id=1')->queryScalar() > 0, 'legacy login updates normal activity');
authResponseCheck(!isset($response['user']['salt']) && !isset($response['user']['password_hash']), 'legacy credentials are not exposed in the login response');
$db->createCommand()->update('user', ['password_hash' => password_hash('new-password', PASSWORD_BCRYPT)], ['id' => 1])->execute();
authResponseCheck(!isset($legacyLogin('legacy-password')['token']) && $app->user->isGuest, 'old password cannot override a reset password');
authResponseCheck(!empty($legacyLogin('new-password')['token']), 'current login still works after reset');
