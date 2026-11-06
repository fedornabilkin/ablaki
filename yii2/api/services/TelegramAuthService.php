<?php
namespace api\services;

use common\services\auth\TelegramConfig;
use yii\db\Connection;
use yii\db\Query;
use yii\web\ConflictHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\GoneHttpException;
use yii\web\NotFoundHttpException;

/** Browser-owned, short-lived approval. The bot never receives a site session or browser proof. */
final class TelegramAuthService
{
    private const REQUESTS = '{{%telegram_auth_request}}';
    private const IDENTITIES = '{{%auth_identity}}';

    public function __construct(private Connection $db, private TelegramConfig $config, private TelegramAccounts $accounts) {}

    public function start(string $purpose, bool $allowSignup, ?int $userId, ?string $authKey): array
    {
        $id = self::randomToken();
        $proof = self::randomToken();
        $code = (string)random_int(100000, 999999);
        $expires = time() + $this->config->ttl;
        $this->db->createCommand()->insert(self::REQUESTS, [
            'id' => $id, 'proof_hash' => hash('sha256', $proof), 'status' => 'pending',
            'purpose' => $purpose, 'allow_signup' => $allowSignup,
            'target_user_id' => $purpose === 'link' ? $userId : null,
            'target_key_hash' => $purpose === 'link' ? hash('sha256', (string)$authKey) : null,
            'display_code' => $code, 'created_at' => time(), 'expires_at' => $expires,
        ])->execute();
        return ['request' => [
            'id' => $id, 'browser_secret' => $proof, 'status' => 'pending',
            'display_code' => $code, 'expires_at' => $expires, 'poll_after_ms' => 2000,
            'telegram_url' => 'https://t.me/' . $this->config->botUsername . '?start=login_' . $id,
        ]];
    }

    public function status(string $id, string $proof, ?int $userId, ?string $authKey): array
    {
        $row = $this->browserRequest($id, $proof, $userId, $authKey);
        return ['request' => $this->view($row)];
    }

    public function cancel(string $id, string $proof, ?int $userId, ?string $authKey): array
    {
        return $this->db->transaction(function () use ($id, $proof, $userId, $authKey) {
            $row = $this->browserRequest($id, $proof, $userId, $authKey, true);
            if (in_array($row['status'], ['pending', 'approved'], true)) {
                $this->update($id, ['status' => 'cancelled']);
                $row['status'] = 'cancelled';
            }
            return ['request' => $this->view($row)];
        });
    }

    /** Called on /start, before sending the confirmation keyboard. Binds approval to that Telegram user. */
    public function prepare(string $id, string $subject, ?string $username): array
    {
        return $this->db->transaction(function () use ($id, $subject, $username) {
            $row = $this->lockedRequest($id);
            $this->active($row);
            if ($row['subject'] !== null && $row['subject'] !== $subject) {
                throw new ConflictHttpException('Эта заявка уже открыта другим пользователем Telegram.');
            }
            if ($row['subject'] === null) {
                $this->update($id, ['subject' => $subject, 'username' => $username]);
            }
            return ['request' => [
                'id' => $id, 'purpose' => $row['purpose'], 'display_code' => $row['display_code'],
                'expires_at' => (int)$row['expires_at'], 'status' => $row['status'],
            ]];
        });
    }

    public function decide(string $id, string $subject, bool $approve): array
    {
        return $this->db->transaction(function () use ($id, $subject, $approve) {
            $row = $this->lockedRequest($id);
            if ($row['subject'] !== $subject) throw new ForbiddenHttpException('Подтверждать может только получатель сообщения.');
            // Repeated Telegram updates are acknowledgements, never a second account operation.
            $next = $approve ? 'approved' : 'denied';
            if ($row['status'] === $next || ($approve && $row['status'] === 'completed')) {
                return ['request' => ['status' => $row['status']]];
            }
            $this->active($row);
            if ($row['status'] !== 'pending') throw new ConflictHttpException('Ответ уже принят.');
            $this->update($id, ['status' => $next]);
            return ['request' => ['status' => $next]];
        });
    }

    public function complete(string $id, string $proof, ?int $userId, ?string $authKey): array
    {
        return $this->db->transaction(function () use ($id, $proof, $userId, $authKey) {
            $row = $this->browserRequest($id, $proof, $userId, $authKey, true);
            if ($row['status'] === 'completed') {
                // A lost HTTP response can be retried briefly, but a logout/key rotation revokes it.
                if (time() > (int)$row['completed_at'] + 60) throw new GoneHttpException('Вход уже завершён. Начните заново.');
                if ($row['purpose'] === 'link') { $this->validateOwner($row); return ['linked' => true]; }
                $this->lock('{{%user}}', ['id' => $row['result_user_id']]);
                return $this->accounts->session((int)$row['result_user_id'], $row['result_key_hash']);
            }
            $this->active($row);
            if ($row['status'] !== 'approved') throw new ConflictHttpException('Дождитесь подтверждения в Telegram.');

            // One lock per external identity serializes parallel approvals for the same Telegram account.
            $this->db->createCommand()->upsert(self::IDENTITIES, [
                'provider' => 'telegram', 'subject' => $row['subject'], 'created_at' => time(),
            ], false)->execute();
            $identity = $this->lock(self::IDENTITIES, ['provider' => 'telegram', 'subject' => $row['subject']]);
            if ($row['purpose'] === 'link') {
                $this->validateOwner($row);
                if ($identity['user_id'] !== null && (int)$identity['user_id'] !== $userId) {
                    throw new ConflictHttpException('Этот Telegram уже привязан к другому аккаунту.');
                }
                $existing = (new Query())->from(self::IDENTITIES)->where(['provider' => 'telegram', 'user_id' => $userId])->one($this->db);
                if ($existing && $existing['subject'] !== $row['subject']) {
                    throw new ConflictHttpException('К аккаунту уже привязан другой Telegram.');
                }
                $resolvedUser = $userId;
                $response = ['linked' => true];
            } else {
                $resolvedUser = $identity['user_id'] ? (int)$identity['user_id'] : null;
                if ($resolvedUser === null) {
                    if (!(bool)$row['allow_signup']) throw new ConflictHttpException('Telegram ещё не привязан. Войдите паролем и привяжите его в профиле либо разрешите создание нового аккаунта.');
                    $resolvedUser = $this->accounts->create();
                }
                $this->lock('{{%user}}', ['id' => $resolvedUser]);
                $response = $this->accounts->session($resolvedUser);
            }
            $this->db->createCommand()->update(self::IDENTITIES, [
                'user_id' => $resolvedUser, 'username' => $row['username'],
            ], ['id' => $identity['id']])->execute();
            $this->update($id, [
                'status' => 'completed', 'completed_at' => time(), 'result_user_id' => $resolvedUser,
                'result_key_hash' => isset($response['token']) ? hash('sha256', $response['token']) : null,
            ]);
            $this->db->createCommand()->insert('{{%auth_audit}}', [
                'event' => $row['purpose'] === 'link' ? 'telegram.link' : 'telegram.login',
                'user_id' => $resolvedUser, 'created_at' => time(),
            ])->execute();
            return $response;
        });
    }

    public function identity(int $userId): ?array
    {
        $row = (new Query())->from(self::IDENTITIES)->where(['provider' => 'telegram', 'user_id' => $userId])->one($this->db);
        return $row ? ['provider' => 'telegram', 'username' => $row['username'], 'connected' => true] : null;
    }

    private function browserRequest(string $id, string $proof, ?int $userId, ?string $authKey, bool $lock = false): array
    {
        $row = $lock ? $this->lockedRequest($id) : (new Query())->from(self::REQUESTS)->where(['id' => $id])->one($this->db);
        if (!$row || !hash_equals($row['proof_hash'], hash('sha256', $proof))) throw new NotFoundHttpException('Заявка не найдена.');
        if ($row['purpose'] === 'link' && ((int)$row['target_user_id'] !== $userId
            || !hash_equals($row['target_key_hash'], hash('sha256', (string)$authKey)))) {
            throw new ForbiddenHttpException('Войдите в аккаунт, из которого начата привязка.');
        }
        return $row;
    }

    private function validateOwner(array $row): void
    {
        $owner = $this->lock('{{%user}}', ['id' => $row['target_user_id']]);
        if (!$owner || !empty($owner['blocked_at']) || !hash_equals($row['target_key_hash'], hash('sha256', (string)$owner['auth_key']))) {
            throw new ForbiddenHttpException('Сессия привязки завершена. Войдите заново.');
        }
    }

    private function view(array $row): array
    {
        $status = in_array($row['status'], ['pending', 'approved'], true) && (int)$row['expires_at'] <= time() ? 'expired' : $row['status'];
        return ['status' => $status, 'expires_at' => (int)$row['expires_at'],
            'telegram' => $status === 'approved' ? ['username' => $row['username']] : null];
    }

    private function active(array $row): void
    {
        if ((int)$row['expires_at'] <= time()) throw new GoneHttpException('Время подтверждения истекло. Начните заново.');
        if (!in_array($row['status'], ['pending', 'approved'], true)) throw new ConflictHttpException('Заявка уже закрыта.');
    }

    private function lockedRequest(string $id): array
    {
        $row = $this->lock(self::REQUESTS, ['id' => $id]);
        if (!$row) throw new NotFoundHttpException('Заявка не найдена.');
        return $row;
    }

    private function lock(string $table, array $where): ?array
    {
        $command = (new Query())->from($table)->where($where)->createCommand($this->db);
        $row = $this->db->createCommand($command->sql . ($this->db->driverName === 'sqlite' ? '' : ' FOR UPDATE'), $command->params)->queryOne();
        return $row ?: null;
    }

    private function update(string $id, array $values): void
    {
        $this->db->createCommand()->update(self::REQUESTS, $values, ['id' => $id])->execute();
    }

    private static function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
