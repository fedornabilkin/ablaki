<?php
// Included by auth-response.php: real API actions, isolated in-memory databases only.
$loginWith = function (string $login, string $password) use ($app) {
    $app->user->setIdentity(null);
    $app->response->setStatusCode(200);
    $app->request->setBodyParams(['login' => $login, 'password' => $password]);
    return authWithoutPasswordWrites($app, 'login');
};
$setLegacy = function ($hash = null) use ($db) {
    $db->createCommand()->update('user', [
        'password_hash' => $hash ?? md5('legacy-password' . md5('fixture-salt')),
        'salt' => 'fixture-salt', 'blocked_at' => null, 'confirmed_at' => 1,
    ], ['id' => 1])->execute();
};

// Fixed vectors from the old Controller::sanitization + Model_Users::encodePassword.
// Explicit strings keep this test independent from the implementation under test.
$legacyVectors = [
    ['plain-password', 'plain-password'],
    ['  spaced-password  ', 'spaced-password'],
    ['amp&password', 'amp&amp;password'],
    ['quote"password', 'quote&quot;password'],
    ["single'password", "single\\'password"],
    ['back\\slash', 'back\\\\slash'],
    ['<b>tagged</b>password', 'taggedpassword'],
    ['entity&amp;password', 'entity&amp;amp;password'],
    ['Пароль123', 'Пароль123'],
];
foreach ($legacyVectors as $index => list($input, $storedInput)) {
    $setLegacy(md5($storedInput . md5('fixture-salt')));
    $response = $loginWith('FixtureUser', $input);
    authResponseCheck($app->response->statusCode === 200 && !empty($response['token']),
        'legacy preprocessing vector ' . $index . ' authenticates');
    $stored = $db->createCommand('SELECT password_hash FROM user WHERE id=1')->queryScalar();
    authResponseCheck($stored === md5($storedInput . md5('fixture-salt')), 'legacy login preserves the original MD5 hash');
    authResponseCheck(!empty($loginWith('fixture@example.invalid', $input)['token']),
        'legacy password authenticates repeatedly by email without migration');
}

// Only credentials in the selected user row are considered; no users table exists.
authResponseCheck($db->getTableSchema('users') === null, 'legacy login requires no separate users table');
foreach ([null, ''] as $missingSalt) {
    $setLegacy();
    $db->createCommand()->update('user', ['salt' => $missingSalt], ['id' => 1])->execute();
    authResponseCheck(!isset($loginWith('FixtureUser', 'legacy-password')['token'])
        && $app->response->statusCode === 401, 'missing legacy salt rejects login safely');
}
foreach (['', 'not-a-hash', str_repeat('g', 32)] as $invalidHash) {
    $setLegacy($invalidHash);
    authResponseCheck(!isset($loginWith('FixtureUser', 'legacy-password')['token']), 'invalid stored hash cannot authenticate');
}
$setLegacy(strtoupper(md5('legacy-password' . md5('fixture-salt'))));
authResponseCheck(!empty($loginWith('  FixtureUser  ', 'legacy-password')['token']),
    'legacy hex hash is case insensitive and login whitespace is trimmed');
$setLegacy();
authResponseCheck(!isset($loginWith('MissingUser', 'legacy-password')['token']), 'legacy password cannot authenticate an unknown username');

// Exercise the real registration action instead of inserting a precomputed modern hash.
$db->createCommand('ALTER TABLE user ADD COLUMN registration_ip TEXT')->execute();
$db->createCommand('CREATE TABLE profile (user_id INTEGER PRIMARY KEY, gravatar_email TEXT, gravatar_id TEXT)')->execute();
$db->schema->refresh();
$module = $app->getModule('user');
$module->enableConfirmation = false;
$module->enableUnconfirmedLogin = true;
Yii::$container->set(\dektrium\user\Mailer::class, \api\components\SilentMailer::class);
// Registration sets a flash message; keep it in memory without emitting session headers.
$app->set('session', new class extends \yii\web\Session {
    public function open() { if (!isset($_SESSION)) $_SESSION = []; }
});
$newPassword = '  New&"password\\123  ';
$app->request->setBodyParams(['username' => 'RegisteredUser', 'email' => 'registered@example.invalid', 'password' => $newPassword]);
$registered = $app->runAction('site/registration');
authResponseCheck($registered['result'] === true, 'real registration creates a new account');
$newUser = \api\modules\v1\models\User::findOne(['username' => 'RegisteredUser']);
authResponseCheck(password_verify($newPassword, $newUser->password_hash), 'registration stores a modern hash of the exact password');
foreach (['RegisteredUser', 'registered@example.invalid'] as $identifier) {
    authResponseCheck(!empty($loginWith($identifier, $newPassword)['token']), 'new account logs in by username or email');
}
authResponseCheck(!isset($loginWith('RegisteredUser', trim($newPassword))['token']), 'modern passwords retain leading and trailing spaces');
$newUser->resetPassword('replacement-password');
authResponseCheck(!isset($loginWith('RegisteredUser', $newPassword)['token'])
    && !empty($loginWith('RegisteredUser', 'replacement-password')['token']), 'real password reset invalidates the previous modern password');
$newUser->block();
authResponseCheck(!isset($loginWith('RegisteredUser', 'replacement-password')['token']), 'blocked modern account cannot log in');
$newUser->unblock();
$newUser->updateAttributes(['confirmed_at' => null]);
$module->enableConfirmation = true;
$module->enableUnconfirmedLogin = false;
authResponseCheck(!isset($loginWith('RegisteredUser', 'replacement-password')['token']), 'required email confirmation is enforced');
$setLegacy();
$db->createCommand()->update('user', ['confirmed_at' => null], ['id' => 1])->execute();
authResponseCheck(!isset($loginWith('FixtureUser', 'legacy-password')['token'])
    && $db->createCommand('SELECT password_hash FROM user WHERE id=1')->queryScalar() === md5('legacy-password' . md5('fixture-salt')),
    'unconfirmed legacy account cannot log in or change its password');
