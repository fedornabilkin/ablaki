<?php

namespace common\services\user;

use dektrium\user\models\User;

/** Compatibility with test/app/models/model_users.php; never supersedes a new password. */
class LegacyPasswordService
{
    public function verify(User $user, string $password): bool
    {
        // Both generations are stored in user.password_hash. A modern hash must
        // never fall back to the old algorithm, even if the old salt remains.
        $hash = $user->getAttribute('password_hash');
        $salt = $user->getAttribute('salt');
        if (!is_string($hash) || !preg_match('/^[a-f0-9]{32}$/iD', $hash)
            || !is_string($salt) || $salt === '') return false;

        // The old controller sanitized every POST value before encodePassword().
        // Pin the PHP 5/7 flags: PHP 8.1 changed htmlspecialchars() defaults.
        // Only legacy verification uses this; the upgrade hashes the original input.
        $legacyPassword = addslashes(htmlspecialchars(strip_tags(trim($password)), ENT_COMPAT | ENT_HTML401, 'UTF-8'));
        return hash_equals(strtolower($hash), md5($legacyPassword . md5($salt)));
    }
}
