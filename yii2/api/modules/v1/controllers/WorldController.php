<?php
namespace api\modules\v1\controllers;

use api\modules\v1\traites\AuthTrait;
use common\modules\world\service\WorldAccessPolicy;
use common\modules\world\service\WorldFlags;
use common\modules\world\service\WorldQuery;
use common\modules\world\service\WorldTree;
use common\modules\world\service\WorldOnboarding;
use common\modules\craft\service\WorldStorage;
use common\services\game\CommandBus;
use common\services\game\GameError;
use Yii;
use yii\db\Query;

class WorldController extends \yii\rest\Controller
{
    use AuthTrait;
    public function authExceptAction(): array { return ['options']; }
    protected function verbs() { return ['index' => ['GET'], 'node' => ['GET'], 'children' => ['GET'], 'navigation' => ['GET'], 'map' => ['GET'], 'statistics' => ['GET'], 'actions' => ['GET'], 'campsite' => ['GET'], 'onboarding' => ['GET'], 'join-preview' => ['POST'], 'join' => ['POST'], 'move-preview' => ['POST'], 'move' => ['POST'], 'archive-preview' => ['POST'], 'archive' => ['POST'], 'storages' => ['GET'], 'storage' => ['GET'], 'storage-transfer-preview' => ['POST'], 'storage-transfer' => ['POST']]; }
    public function runAction($id, $params = [])
    {
        try { return parent::runAction($id, $params); }
        catch (GameError $error) {
            Yii::$app->response->statusCode = $error->status;
            return ['code' => $error->reason, 'message' => $error->getMessage(), 'details' => (object)$error->details];
        }
        catch (\yii\web\HttpException $error) {
            if ($error->statusCode >= 500) throw $error;
            Yii::$app->response->statusCode = $error->statusCode;
            return ['code' => 'WORLD_REQUEST_REJECTED', 'message' => $error->getMessage(), 'details' => (object)[]];
        }
    }
    private function flags(): WorldFlags { return new WorldFlags(Yii::$app->db, Yii::$app->getModule('world')); }
    private function achievementService(): \common\modules\progression\service\WorldAchievements { return new \common\modules\progression\service\WorldAchievements(Yii::$app->db, $this->flags(), $this->policy()); }
    public function actionAchievements(): array
    {
        if (!Yii::$app->request->isGet) throw new \yii\web\MethodNotAllowedHttpException('Используйте GET.');
        $status = Yii::$app->request->get('status', 'all');
        if (!is_string($status)) throw new GameError('INVALID_FILTER', 'Некорректный фильтр.', 422);
        return $this->achievementService()->listing((int)Yii::$app->user->id, $this->id(Yii::$app->request->get('page', 1)), $this->orderSearch(), $status);
    }
    private function achievementPublish(bool $preview): array
    {
        if (!Yii::$app->request->isPost) throw new \yii\web\MethodNotAllowedHttpException('Используйте POST.');
        $body = Yii::$app->request->bodyParams;
        if (!is_array($body)) throw new GameError('INVALID_COMMAND', 'Некорректное действие.', 422);
        $service = $this->achievementService(); $input = $service->input($body); $user = (int)Yii::$app->user->id;
        if ($preview) return $service->preview($user, $input);
        if (!is_string($body['request_key'] ?? null) || !is_string($body['quote_id'] ?? null) || !is_array($body['expected_revisions'] ?? null)) throw new GameError('INVALID_COMMAND', 'Требуется подтверждённый расчёт.', 422);
        return $service->publish($user, $input, $body['request_key'], $body['quote_id'], $body['expected_revisions']);
    }
    public function actionAchievementPublishPreview(): array { return $this->achievementPublish(true); }
    public function actionAchievementPublish(): array { return $this->achievementPublish(false); }
    private function cultivationService(): \common\modules\world\service\WorldCultivation { return new \common\modules\world\service\WorldCultivation(Yii::$app->db, $this->flags(), $this->policy()); }
    public function actionCrops(): array
    {
        if (!Yii::$app->request->isGet) throw new \yii\web\MethodNotAllowedHttpException('Используйте GET.');
        return $this->cultivationService()->crops($this->id(Yii::$app->request->get('page', 1)), $this->orderSearch());
    }
    public function actionCultivation($id): array
    {
        if (!Yii::$app->request->isGet) throw new \yii\web\MethodNotAllowedHttpException('Используйте GET.');
        return $this->cultivationService()->state((int)Yii::$app->user->id, $this->id($id));
    }
    private function cultivationCommand($id, string $action, bool $preview): array
    {
        if (!Yii::$app->request->isPost) throw new \yii\web\MethodNotAllowedHttpException('Используйте POST.');
        $body = Yii::$app->request->bodyParams;
        if (!is_array($body)) throw new GameError('INVALID_COMMAND', 'Некорректное действие.', 422);
        $service = $this->cultivationService(); $user = (int)Yii::$app->user->id;
        $input = $action === 'publish' ? $service->publication($body) : ['bed_id' => $this->id($id)];
        if ($action === 'sow') $input['crop_revision_id'] = $this->id($body['crop_revision_id'] ?? null);
        if ($preview) return $service->preview($user, $action, $input);
        if (!is_string($body['request_key'] ?? null) || !is_string($body['quote_id'] ?? null) || !is_array($body['expected_revisions'] ?? null)) throw new GameError('INVALID_COMMAND', 'Требуется подтверждённый расчёт.', 422);
        return $service->execute($user, $action, $input, $body['request_key'], $body['quote_id'], $body['expected_revisions']);
    }
    public function actionCultivationPreview($id, string $action): array { return $this->cultivationCommand($id, $action, true); }
    public function actionCultivationExecute($id, string $action): array { return $this->cultivationCommand($id, $action, false); }
    public function actionCropPublishPreview(): array { return $this->cultivationCommand(null, 'publish', true); }
    public function actionCropPublish(): array { return $this->cultivationCommand(null, 'publish', false); }
    private function notificationService(): \common\modules\world\service\WorldNotifications { return new \common\modules\world\service\WorldNotifications(Yii::$app->db, $this->flags()); }
    public function actionNotifications(): array
    {
        if (!Yii::$app->request->isGet) throw new \yii\web\MethodNotAllowedHttpException('Используйте GET.');
        $status = Yii::$app->request->get('status', 'all'); $type = Yii::$app->request->get('type', '');
        if (!is_string($status) || !is_string($type)) throw new GameError('INVALID_FILTER', 'Некорректный фильтр.', 422);
        return $this->notificationService()->listing((int)Yii::$app->user->id, $this->id(Yii::$app->request->get('page', 1)), $this->orderSearch(), $status, $type);
    }
    public function actionNotificationRead($id): array
    {
        if (!Yii::$app->request->isPost) throw new \yii\web\MethodNotAllowedHttpException('Используйте POST.');
        return $this->notificationService()->read((int)Yii::$app->user->id, $this->id($id));
    }
    public function actionNotificationPreferences(): array
    {
        if (!Yii::$app->request->isGet) throw new \yii\web\MethodNotAllowedHttpException('Используйте GET.');
        return $this->notificationService()->preferences((int)Yii::$app->user->id);
    }
    public function actionNotificationConfigure(): array
    {
        if (!Yii::$app->request->isPost) throw new \yii\web\MethodNotAllowedHttpException('Используйте POST.');
        $body = Yii::$app->request->bodyParams;
        if (!is_array($body)) throw new GameError('INVALID_COMMAND', 'Некорректные настройки.', 422);
        return $this->notificationService()->configure((int)Yii::$app->user->id, $body);
    }
    private function progressionService(): \common\modules\progression\service\WorldProgression
    {
        return new \common\modules\progression\service\WorldProgression(Yii::$app->db, $this->flags(), $this->policy());
    }
    public function actionProfessions(): array
    {
        if (!Yii::$app->request->isGet) throw new \yii\web\MethodNotAllowedHttpException('Используйте GET.');
        return $this->progressionService()->listing((int)Yii::$app->user->id, $this->id(Yii::$app->request->get('page', 1)), $this->orderSearch());
    }
    private function professionCommand($id, string $action, bool $preview): array
    {
        if (!Yii::$app->request->isPost) throw new \yii\web\MethodNotAllowedHttpException('Используйте POST.');
        $body = Yii::$app->request->bodyParams;
        if (!is_array($body)) throw new GameError('INVALID_COMMAND', 'Некорректное действие.', 422);
        $service = $this->progressionService(); $user = (int)Yii::$app->user->id;
        $input = $action === 'publish' ? $service->publication($body) : ['profession_id' => $this->id($id)];
        if ($preview) return $service->preview($user, $action, $input);
        if (!is_string($body['request_key'] ?? null) || !is_string($body['quote_id'] ?? null) || !is_array($body['expected_revisions'] ?? null)) throw new GameError('INVALID_COMMAND', 'Требуется подтверждённый расчёт.', 422);
        return $service->execute($user, $action, $input, $body['request_key'], $body['quote_id'], $body['expected_revisions']);
    }
    public function actionProfessionPreview($id, string $action): array { return $this->professionCommand($id, $action, true); }
    public function actionProfessionExecute($id, string $action): array { return $this->professionCommand($id, $action, false); }
    public function actionProfessionPublishPreview(): array { return $this->professionCommand(null, 'publish', true); }
    public function actionProfessionPublish(): array { return $this->professionCommand(null, 'publish', false); }
    private function policy(): WorldAccessPolicy
    {
        return new WorldAccessPolicy((int)Yii::$app->user->id, Yii::$app->user->can('world-manage'));
    }
    private function reader(): WorldQuery { $this->flags()->requireFlag('world_read'); return new WorldQuery(Yii::$app->db, $this->policy()); }
    private function id($value): int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^[1-9][0-9]*$/D', (string)$value) || (float)$value > 2147483647) throw new GameError('INVALID_ID', 'Некорректный идентификатор.', 422);
        return (int)$value;
    }
    public function actionIndex(): array
    {
        $capabilities = $this->flags()->capabilities();
        $result = ['contract_version' => 1, 'server_time' => time(), 'capabilities' => $capabilities, 'world' => null, 'regions' => ['items' => [], '_meta' => ['totalCount' => 0, 'pageCount' => 0, 'currentPage' => 1, 'perPage' => 20]]];
        if (!$capabilities['world_read']) return $result;
        $registry = (new Query())->from('world_registry')->where(['id' => 1])->one(Yii::$app->db);
        if (!$registry['active_world_id']) return $result;
        $reader = $this->reader(); $id = (int)$registry['active_world_id'];
        $result['world'] = $reader->node($id); $result['regions'] = $reader->children($id, Yii::$app->request->queryParams);
        return $result;
    }
    private function campsiteActions(array $node): ?array
    {
        if ($node['type'] !== 'PLOT' || (((array)$node['details'])['plot_kind'] ?? null) !== 'campsite' || !$node['permissions']['storage']) return null;
        $user = (int)Yii::$app->user->id;
        if (!(new Query())->from('world_membership')->where(['user_id' => $user, 'world_id' => $node['root_id'], 'starter_site_id' => $node['id']])->exists(Yii::$app->db)) return null;
        return (new \common\modules\world\service\WorldCampsite(Yii::$app->db, $this->flags()))->actions($user, (int)$node['id']);
    }
    public function actionNode($id): array
    {
        $node = $this->reader()->node($this->id($id)); $actions = $this->campsiteActions($node);
        if ($actions) $node['actions'] = $actions['items'];
        return $node;
    }
    public function actionChildren($id): array { return $this->reader()->children($this->id($id), Yii::$app->request->queryParams); }
    public function actionNavigation($id): array { return $this->reader()->navigation($this->id($id)); }
    public function actionMap($id): array { return $this->reader()->children($this->id($id), Yii::$app->request->queryParams); }
    public function actionStatistics($id): array { return ['items' => $this->reader()->statistics($this->id($id))]; }
    public function actionActions($id): array
    {
        $node = $this->reader()->node($this->id($id));
        return $this->campsiteActions($node) ?: ['items' => $node['actions']];
    }
    public function actionCampsite($id): array
    {
        return (new \common\modules\world\service\WorldCampsite(Yii::$app->db, $this->flags()))->state((int)Yii::$app->user->id, $this->id($id));
    }
    public function actionOnboarding(): array { return (new WorldOnboarding(Yii::$app->db, $this->flags()))->state((int)Yii::$app->user->id); }
    public function actionStorages(): array
    {
        $node = Yii::$app->request->get('node_id');
        return (new WorldStorage(Yii::$app->db, $this->flags()))->list((int)Yii::$app->user->id, $node === null ? null : $this->id($node));
    }
    public function actionStorage($id): array
    {
        return (new WorldStorage(Yii::$app->db, $this->flags()))->view((int)Yii::$app->user->id, $this->id($id), $this->id(Yii::$app->request->get('page', 1)));
    }
    private function storageTransfer(bool $preview): array
    {
        $body = Yii::$app->request->bodyParams;
        if (!is_array($body)) throw new GameError('INVALID_COMMAND', 'Некорректное действие.', 422);
        $payload = [];
        foreach (['inventory_id', 'source_storage_id', 'destination_storage_id', 'position', 'quantity'] as $field) $payload[$field] = $this->id($body[$field] ?? null);
        $payload['instance_id'] = ($body['instance_id'] ?? null) === null ? null : $this->id($body['instance_id']);
        $service = new WorldStorage(Yii::$app->db, $this->flags()); $user = (int)Yii::$app->user->id;
        if ($preview) return $service->preview($user, $payload);
        if (!is_string($body['request_key'] ?? null) || !is_string($body['quote_id'] ?? null) || !is_array($body['expected_revisions'] ?? null)) throw new GameError('INVALID_COMMAND', 'Требуется подтверждённый расчёт.', 422);
        return $service->transfer($user, $body['request_key'], $payload, $body['quote_id'], $body['expected_revisions']);
    }
    public function actionStorageTransferPreview(): array { return $this->storageTransfer(true); }
    public function actionStorageTransfer(): array { return $this->storageTransfer(false); }
    private function chestRepair(bool $preview): array
    {
        if (!Yii::$app->request->isPost) throw new \yii\web\MethodNotAllowedHttpException('Используйте POST.');
        $body = Yii::$app->request->bodyParams;
        if (!is_array($body)) throw new GameError('INVALID_COMMAND', 'Некорректное действие.', 422);
        $payload = ['storage_id' => $this->id($body['storage_id'] ?? null), 'container_inventory_id' => $this->id($body['container_inventory_id'] ?? null)];
        $service = new \common\modules\craft\service\WorldChestRepair(Yii::$app->db, $this->flags()); $user = (int)Yii::$app->user->id;
        if ($preview) return $service->preview($user, $payload);
        if (!is_string($body['request_key'] ?? null) || !is_string($body['quote_id'] ?? null) || !is_array($body['expected_revisions'] ?? null)) throw new GameError('INVALID_COMMAND', 'Требуется подтверждённый расчёт.', 422);
        return $service->repair($user, $body['request_key'], $payload, $body['quote_id'], $body['expected_revisions']);
    }
    public function actionChestRepairPreview(): array { return $this->chestRepair(true); }
    public function actionChestRepair(): array { return $this->chestRepair(false); }
    public function actionWorkspace(): array
    {
        if (!Yii::$app->request->isGet) throw new \yii\web\MethodNotAllowedHttpException('Используйте GET.');
        $recipe = Yii::$app->request->get('recipe_id');
        return (new \common\modules\craft\service\CraftWorkspace(Yii::$app->db, $this->flags()))->state((int)Yii::$app->user->id, $this->id(Yii::$app->request->get('node_id')), $recipe === null ? null : $this->id($recipe));
    }
    private function workspaceCraft(bool $preview): array
    {
        if (!Yii::$app->request->isPost) throw new \yii\web\MethodNotAllowedHttpException('Используйте POST.');
        $body = Yii::$app->request->bodyParams;
        if (!is_array($body)) throw new GameError('INVALID_COMMAND', 'Некорректное действие.', 422);
        $payload = [];
        foreach (['node_id', 'recipe_id', 'quantity', 'output_storage_id'] as $field) $payload[$field] = $this->id($body[$field] ?? null);
        if ($payload['quantity'] > 100) throw new GameError('INVALID_QUANTITY', 'Количество должно быть от 1 до 100.', 422);
        foreach (['source_storage_ids' => 20, 'equipment_instance_ids' => 100] as $field => $limit) {
            $values = $body[$field] ?? null;
            if (!is_array($values) || array_values($values) !== $values || count($values) > $limit || ($field === 'source_storage_ids' && !$values)) throw new GameError('INVALID_SELECTION', 'Некорректный список хранилищ или оборудования.', 422);
            $payload[$field] = array_map(function ($id) { return $this->id($id); }, $values);
            if (count(array_unique($payload[$field])) !== count($values)) throw new GameError('DUPLICATE_SELECTION', 'Уберите повторяющиеся хранилища или экземпляры.', 422);
        }
        sort($payload['equipment_instance_ids']);
        $service = new \common\modules\craft\service\CraftWorkspace(Yii::$app->db, $this->flags()); $user = (int)Yii::$app->user->id;
        if ($preview) return $service->preview($user, $payload);
        if (!is_string($body['request_key'] ?? null) || !is_string($body['quote_id'] ?? null) || !is_array($body['expected_revisions'] ?? null)) throw new GameError('INVALID_COMMAND', 'Требуется подтверждённый расчёт.', 422);
        return $service->craft($user, $body['request_key'], $payload, $body['quote_id'], $body['expected_revisions']);
    }
    public function actionWorkspacePreview(): array { return $this->workspaceCraft(true); }
    public function actionWorkspaceCraft(): array { return $this->workspaceCraft(false); }
    public function actionRecovery(): array
    {
        if (!Yii::$app->request->isGet) throw new \yii\web\MethodNotAllowedHttpException('Используйте GET.');
        return (new \common\modules\craft\service\StorageRecovery(Yii::$app->db, $this->flags()))->listing((int)Yii::$app->user->id, $this->id(Yii::$app->request->get('page', 1)));
    }
    private function recovery(bool $preview): array
    {
        if (!Yii::$app->request->isPost) throw new \yii\web\MethodNotAllowedHttpException('Используйте POST.');
        $body = Yii::$app->request->bodyParams;
        if (!is_array($body)) throw new GameError('INVALID_COMMAND', 'Некорректное действие.', 422);
        $inventory = $this->id($body['inventory_id'] ?? null); $user = (int)Yii::$app->user->id;
        $service = new \common\modules\craft\service\StorageRecovery(Yii::$app->db, $this->flags());
        if ($preview) return $service->preview($user, $inventory);
        if (!is_string($body['request_key'] ?? null) || !is_string($body['quote_id'] ?? null) || !is_array($body['expected_revisions'] ?? null)) throw new GameError('INVALID_COMMAND', 'Требуется подтверждённый расчёт.', 422);
        return $service->recover($user, $body['request_key'], $inventory, $body['quote_id'], $body['expected_revisions']);
    }
    public function actionRecoveryPreview(): array { return $this->recovery(true); }
    public function actionRecover(): array { return $this->recovery(false); }
    public function actionEconomy($id): array
    {
        if (!Yii::$app->request->isGet) throw new \yii\web\MethodNotAllowedHttpException('Используйте GET.');
        return (new \common\modules\economy\service\NodeEconomy(Yii::$app->db, $this->flags()))->view((int)Yii::$app->user->id, $this->id($id), $this->id(Yii::$app->request->get('page', 1)));
    }
    private function investment($id, bool $preview): array
    {
        if (!Yii::$app->request->isPost) throw new \yii\web\MethodNotAllowedHttpException('Используйте POST.');
        $body = Yii::$app->request->bodyParams;
        if (!is_array($body) || !is_string($body['amount'] ?? null) || !is_string($body['purpose'] ?? null) || trim($body['purpose']) === '' || mb_strlen($body['purpose'], 'UTF-8') > 255) throw new GameError('INVALID_INVESTMENT', 'Укажите сумму строкой и назначение вложения.', 422);
        try { $amount = \common\modules\economy\value\Money::parse($body['amount']); }
        catch (\InvalidArgumentException $e) { throw new GameError('INVALID_AMOUNT', 'Укажите сумму с точностью до четырёх знаков после точки.', 422); }
        catch (\OverflowException $e) { throw new GameError('INVALID_AMOUNT', 'Сумма превышает допустимый предел.', 422); }
        if ($amount->isNegative() || $amount->isZero()) throw new GameError('INVALID_AMOUNT', 'Укажите положительную сумму.', 422);
        $payload = ['node_id' => $this->id($id), 'amount' => $amount->decimal(), 'purpose' => trim($body['purpose'])];
        $service = new \common\modules\economy\service\NodeEconomy(Yii::$app->db, $this->flags()); $user = (int)Yii::$app->user->id;
        if ($preview) return $service->preview($user, $payload);
        if (!is_string($body['request_key'] ?? null) || !is_string($body['quote_id'] ?? null) || !is_array($body['expected_revisions'] ?? null)) throw new GameError('INVALID_COMMAND', 'Требуется подтверждённый расчёт.', 422);
        return $service->invest($user, $body['request_key'], $payload, $body['quote_id'], $body['expected_revisions']);
    }
    public function actionInvestPreview($id): array { return $this->investment($id, true); }
    public function actionInvest($id): array { return $this->investment($id, false); }
    private function budgetGrant($id, bool $preview): array
    {
        if (!Yii::$app->request->isPost) throw new \yii\web\MethodNotAllowedHttpException('Используйте POST.');
        $body = Yii::$app->request->bodyParams;
        if (!is_array($body) || !is_string($body['amount'] ?? null) || !is_string($body['purpose'] ?? null)
            || trim($body['purpose']) === '' || mb_strlen($body['purpose'], 'UTF-8') > 255) throw new GameError('INVALID_GRANT', 'Укажите сумму и назначение перевода.', 422);
        try { $amount = \common\modules\economy\value\Money::parse($body['amount']); }
        catch (\InvalidArgumentException $e) { throw new GameError('INVALID_AMOUNT', 'Укажите сумму с точностью до четырёх знаков после точки.', 422); }
        catch (\OverflowException $e) { throw new GameError('INVALID_AMOUNT', 'Сумма превышает допустимый предел.', 422); }
        if ($amount->isZero() || $amount->isNegative()) throw new GameError('INVALID_AMOUNT', 'Укажите положительную сумму.', 422);
        $input = ['source_node_id' => $this->id($id), 'destination_node_id' => $this->id($body['destination_node_id'] ?? null),
            'amount' => $amount->decimal(), 'purpose' => trim($body['purpose'])];
        $service = new \common\modules\economy\service\BudgetGrants(Yii::$app->db, $this->flags()); $user = (int)Yii::$app->user->id;
        if ($preview) return $service->preview($user, $input);
        if (!is_string($body['request_key'] ?? null) || !is_string($body['quote_id'] ?? null) || !is_array($body['expected_revisions'] ?? null)) throw new GameError('INVALID_COMMAND', 'Требуется подтверждённый расчёт.', 422);
        return $service->execute($user, $body['request_key'], $input, $body['quote_id'], $body['expected_revisions']);
    }
    public function actionBudgetGrantPreview($id): array { return $this->budgetGrant($id, true); }
    public function actionBudgetGrant($id): array { return $this->budgetGrant($id, false); }
    private function supplies($id, string $operation, bool $preview): array
    {
        if (!Yii::$app->request->isPost) throw new \yii\web\MethodNotAllowedHttpException('Используйте POST.');
        $body = Yii::$app->request->bodyParams;
        if (!is_array($body)) throw new GameError('INVALID_COMMAND', 'Некорректное действие.', 422);
        $input = ['node_id' => $this->id($id), 'action' => $operation];
        $service = new \common\modules\world\service\WorldSupplies(Yii::$app->db, $this->flags()); $user = (int)Yii::$app->user->id;
        if ($preview) return $service->preview($user, $input);
        if (!is_string($body['request_key'] ?? null) || !is_string($body['quote_id'] ?? null) || !is_array($body['expected_revisions'] ?? null)) throw new GameError('INVALID_COMMAND', 'Требуется подтверждённый расчёт.', 422);
        return $service->execute($user, $body['request_key'], $input, $body['quote_id'], $body['expected_revisions']);
    }
    public function actionSuppliesPreview($id, string $operation): array { return $this->supplies($id, $operation, true); }
    public function actionSuppliesExecute($id, string $operation): array { return $this->supplies($id, $operation, false); }
    public function actionObligations($id): array
    {
        if (!Yii::$app->request->isGet) throw new \yii\web\MethodNotAllowedHttpException('Используйте GET.');
        return (new \common\modules\economy\service\NodeEconomy(Yii::$app->db, $this->flags()))->obligations((int)Yii::$app->user->id, $this->id($id), $this->id(Yii::$app->request->get('page', 1)));
    }
    public function actionTreasuryReceipts($id): array
    {
        if (!Yii::$app->request->isGet) throw new \yii\web\MethodNotAllowedHttpException('Используйте GET.');
        $status = Yii::$app->request->get('status', 'open');
        if (!is_string($status)) throw new GameError('INVALID_RECEIPT_FILTER', 'Некорректный статус поступлений.', 422);
        return (new \common\modules\economy\service\TreasuryReceipts(Yii::$app->db, $this->flags()))->listing((int)Yii::$app->user->id, $this->id($id), $this->id(Yii::$app->request->get('page', 1)), $this->orderSearch(), $status);
    }
    public function actionFinanceReport($id): array
    {
        if (!Yii::$app->request->isGet) throw new \yii\web\MethodNotAllowedHttpException('Используйте GET.');
        $kind = Yii::$app->request->get('kind', 'all'); $direction = Yii::$app->request->get('direction', 'all');
        if (!is_string($kind) || !is_string($direction)) throw new GameError('INVALID_FINANCE_FILTER', 'Некорректные фильтры отчёта.', 422);
        return (new \common\modules\economy\service\NodeFinanceReport(Yii::$app->db, $this->flags()))->view((int)Yii::$app->user->id, $this->id($id), $this->id(Yii::$app->request->get('page', 1)), $this->orderSearch(), $kind, $direction);
    }
    public function actionFinanceHierarchy($id): array
    {
        if (!Yii::$app->request->isGet) throw new \yii\web\MethodNotAllowedHttpException('Используйте GET.');
        return (new \common\modules\economy\service\HierarchyFinanceReport(Yii::$app->db, $this->flags()))->view((int)Yii::$app->user->id, $this->id($id), $this->id(Yii::$app->request->get('page', 1)), $this->orderSearch());
    }
    private function treasury($id, bool $pay, bool $preview): array
    {
        if (!Yii::$app->request->isPost) throw new \yii\web\MethodNotAllowedHttpException('Используйте POST.');
        $body = Yii::$app->request->bodyParams;
        if (!is_array($body)) throw new GameError('INVALID_COMMAND', 'Некорректное действие.', 422);
        $input = ['node_id' => $this->id($id)];
        if ($pay) $input['obligation_id'] = $this->id($body['obligation_id'] ?? null);
        $service = new \common\modules\economy\service\TreasuryCommands(Yii::$app->db, $this->flags()); $user = (int)Yii::$app->user->id;
        if ($preview) return $service->preview($user, $input, $pay);
        if (!is_string($body['request_key'] ?? null) || !is_string($body['quote_id'] ?? null) || !is_array($body['expected_revisions'] ?? null)) throw new GameError('INVALID_COMMAND', 'Требуется подтверждённый расчёт.', 422);
        return $service->execute($user, $body['request_key'], $input, $body['quote_id'], $body['expected_revisions'], $pay);
    }
    public function actionCollectPreview($id): array { return $this->treasury($id, false, true); }
    public function actionCollect($id): array { return $this->treasury($id, false, false); }
    public function actionPayPreview($id): array { return $this->treasury($id, true, true); }
    public function actionPay($id): array { return $this->treasury($id, true, false); }
    private function financePolicy($id, bool $preview): array
    {
        if (!Yii::$app->request->isPost) throw new \yii\web\MethodNotAllowedHttpException('Используйте POST.');
        $this->policy()->requireAdmin(); $body = Yii::$app->request->bodyParams;
        if (!is_array($body)) throw new GameError('INVALID_COMMAND', 'Некорректное действие.', 422);
        $input = \common\modules\economy\service\CollectionPolicy::input($body, $this->id($id));
        $service = new \common\modules\economy\service\CollectionPolicy(Yii::$app->db, $this->flags(), $this->policy()); $user = (int)Yii::$app->user->id;
        if ($preview) return $service->preview($user, $input);
        if (!is_string($body['request_key'] ?? null) || !is_string($body['quote_id'] ?? null) || !is_array($body['expected_revisions'] ?? null)) throw new GameError('INVALID_COMMAND', 'Требуется подтверждённый расчёт.', 422);
        return $service->publish($user, $body['request_key'], $input, $body['quote_id'], $body['expected_revisions']);
    }
    public function actionFinancePolicyPreview($id): array { return $this->financePolicy($id, true); }
    public function actionFinancePolicy($id): array { return $this->financePolicy($id, false); }
    private function orderService(): \common\modules\economy\service\StarterOrders
    {
        return new \common\modules\economy\service\StarterOrders(Yii::$app->db, $this->flags(), $this->policy());
    }
    private function orderSearch(): string
    {
        $search = Yii::$app->request->get('q', '');
        if (!is_string($search) || mb_strlen($search, 'UTF-8') > 120) throw new GameError('INVALID_ORDER_FILTER', 'Некорректная строка поиска.', 422);
        return trim($search);
    }
    public function actionOrders($id): array
    {
        if (!Yii::$app->request->isGet) throw new \yii\web\MethodNotAllowedHttpException('Используйте GET.');
        $status = Yii::$app->request->get('status', 'open');
        if (!is_string($status)) throw new GameError('INVALID_ORDER_FILTER', 'Некорректный статус.', 422);
        return $this->orderService()->listing((int)Yii::$app->user->id, $this->id($id), $this->id(Yii::$app->request->get('page', 1)), $this->orderSearch(), $status);
    }
    public function actionOrderItems($id): array
    {
        if (!Yii::$app->request->isGet) throw new \yii\web\MethodNotAllowedHttpException('Используйте GET.');
        return $this->orderService()->catalog((int)Yii::$app->user->id, $this->id($id), $this->id(Yii::$app->request->get('page', 1)), $this->orderSearch());
    }
    public function actionOrderStock($id): array
    {
        if (!Yii::$app->request->isGet) throw new \yii\web\MethodNotAllowedHttpException('Используйте GET.');
        return $this->orderService()->stock((int)Yii::$app->user->id, $this->id($id), $this->id(Yii::$app->request->get('item_id')), $this->id(Yii::$app->request->get('page', 1)));
    }
    private function orderCommand($id, string $action, bool $preview): array
    {
        if (!Yii::$app->request->isPost) throw new \yii\web\MethodNotAllowedHttpException('Используйте POST.');
        $body = Yii::$app->request->bodyParams;
        if (!is_array($body)) throw new GameError('INVALID_COMMAND', 'Некорректное действие.', 422);
        $input = ['node_id' => $this->id($id)];
        if ($action === 'publish') {
            foreach (['item_id', 'quantity', 'per_user_limit', 'lifetime_hours'] as $field) $input[$field] = $this->id($body[$field] ?? null);
            if ($input['quantity'] > 1000000 || $input['per_user_limit'] > $input['quantity'] || $input['lifetime_hours'] > 720) throw new GameError('INVALID_ORDER_LIMIT', 'Проверьте количество, лимит на игрока и срок до 720 часов.', 422);
            if (!is_string($body['unit_price'] ?? null) || !is_string($body['purpose'] ?? null) || trim($body['purpose']) === '' || mb_strlen($body['purpose'], 'UTF-8') > 255 || ($body['initialize_starter_policy'] ?? null) !== true) throw new GameError('INVALID_ORDER', 'Укажите цену, назначение и подтвердите начальные условия стоянок.', 422);
            try { $price = \common\modules\economy\value\Money::parse($body['unit_price']); }
            catch (\InvalidArgumentException $e) { throw new GameError('INVALID_AMOUNT', 'Цена должна быть десятичной строкой до четырёх знаков после точки.', 422); }
            catch (\OverflowException $e) { throw new GameError('INVALID_AMOUNT', 'Цена превышает допустимый предел.', 422); }
            if ($price->isZero() || $price->isNegative()) throw new GameError('INVALID_AMOUNT', 'Цена должна быть положительной.', 422);
            $input += ['unit_price' => $price->decimal(), 'purpose' => trim($body['purpose']), 'initialize_starter_policy' => true];
        } else {
            $input['order_id'] = $this->id($body['order_id'] ?? null);
            if ($action === 'deliver') {
                $input['inventory_id'] = $this->id($body['inventory_id'] ?? null); $input['quantity'] = $this->id($body['quantity'] ?? null);
                if (array_key_exists('income_node_id', $body)) $input['income_node_id'] = $this->id($body['income_node_id']);
                if ($input['quantity'] > 10000) throw new GameError('INVALID_QUANTITY', 'Можно сдать до 10000 предметов за раз.', 422);
            }
        }
        $service = $this->orderService(); $user = (int)Yii::$app->user->id;
        if ($preview) return $service->preview($user, $input, $action);
        if (!is_string($body['request_key'] ?? null) || !is_string($body['quote_id'] ?? null) || !is_array($body['expected_revisions'] ?? null)) throw new GameError('INVALID_COMMAND', 'Требуется подтверждённый расчёт.', 422);
        return $service->execute($user, $body['request_key'], $input, $body['quote_id'], $body['expected_revisions'], $action);
    }
    public function actionOrderPublishPreview($id): array { return $this->orderCommand($id, 'publish', true); }
    public function actionOrderPublish($id): array { return $this->orderCommand($id, 'publish', false); }
    public function actionOrderDeliverPreview($id): array { return $this->orderCommand($id, 'deliver', true); }
    public function actionOrderDeliver($id): array { return $this->orderCommand($id, 'deliver', false); }
    public function actionOrderCancelPreview($id): array { return $this->orderCommand($id, 'cancel', true); }
    public function actionOrderCancel($id): array { return $this->orderCommand($id, 'cancel', false); }
    public function actionShelter($id): array
    {
        if (!Yii::$app->request->isGet) throw new \yii\web\MethodNotAllowedHttpException('Используйте GET.');
        return (new \common\modules\world\service\WorldShelter(Yii::$app->db, $this->flags()))->state((int)Yii::$app->user->id, $this->id($id));
    }
    public function actionHousing($id): array
    {
        if (!Yii::$app->request->isGet) throw new \yii\web\MethodNotAllowedHttpException('Используйте GET.');
        return (new \common\modules\world\service\WorldHousing(Yii::$app->db, $this->flags()))->state((int)Yii::$app->user->id, $this->id($id));
    }
    public function actionEquipmentWear($id): array
    {
        if (!Yii::$app->request->isGet) throw new \yii\web\MethodNotAllowedHttpException('Используйте GET.');
        $kind = Yii::$app->request->get('kind', ''); $search = Yii::$app->request->get('q', '');
        if (!is_string($kind) || !is_string($search)) throw new GameError('INVALID_WEAR_FILTER', 'Некорректный фильтр износа.', 422);
        return (new \common\modules\craft\service\EquipmentWearHistory(Yii::$app->db))->state((int)Yii::$app->user->id, $this->id($id), $this->id(Yii::$app->request->get('page', 1)), $kind, trim($search), $this->flags());
    }
    private function housingCommand($id, string $operation, bool $preview): array
    {
        if (!Yii::$app->request->isPost) throw new \yii\web\MethodNotAllowedHttpException('Используйте POST.');
        $body = Yii::$app->request->bodyParams;
        if (!is_array($body)) throw new GameError('INVALID_COMMAND', 'Некорректное действие с жильём.', 422);
        $input = ['node_id' => $this->id($id)]; $user = (int)Yii::$app->user->id;
        $service = new \common\modules\world\service\WorldHousing(Yii::$app->db, $this->flags());
        if ($preview) return $service->preview($user, $input, $operation);
        if (!is_string($body['request_key'] ?? null) || !is_string($body['quote_id'] ?? null) || !is_array($body['expected_revisions'] ?? null)) throw new GameError('INVALID_COMMAND', 'Требуется подтверждённый расчёт.', 422);
        return $service->execute($user, $body['request_key'], $input, $body['quote_id'], $body['expected_revisions'], $operation);
    }
    public function actionHousingPreview($id, string $operation): array { return $this->housingCommand($id, $operation, true); }
    public function actionHousingExecute($id, string $operation): array { return $this->housingCommand($id, $operation, false); }
    private function gardenService(): \common\modules\world\service\WorldGarden
    {
        return new \common\modules\world\service\WorldGarden(Yii::$app->db, $this->flags(), $this->policy());
    }
    public function actionGarden($id): array
    {
        if (!Yii::$app->request->isGet) throw new \yii\web\MethodNotAllowedHttpException('Используйте GET.');
        return $this->gardenService()->state((int)Yii::$app->user->id, $this->id($id));
    }
    public function actionEquipmentExpansion($id): array
    {
        if (!Yii::$app->request->isGet) throw new \yii\web\MethodNotAllowedHttpException('Используйте GET.');
        return (new \common\modules\world\service\WorldEquipmentExpansion(Yii::$app->db, $this->flags()))->state((int)Yii::$app->user->id, $this->id($id));
    }
    private function equipmentExpansionCommand($id, bool $preview): array
    {
        if (!Yii::$app->request->isPost) throw new \yii\web\MethodNotAllowedHttpException('Используйте POST.');
        $body = Yii::$app->request->bodyParams;
        if (!is_array($body)) throw new GameError('INVALID_COMMAND', 'Некорректное действие.', 422);
        $service = new \common\modules\world\service\WorldEquipmentExpansion(Yii::$app->db, $this->flags()); $user = (int)Yii::$app->user->id;
        $input = ['node_id' => $this->id($id)] + $service->input($body);
        if ($preview) return $service->preview($user, $input);
        if (!is_string($body['request_key'] ?? null) || !is_string($body['quote_id'] ?? null) || !is_array($body['expected_revisions'] ?? null)) throw new GameError('INVALID_COMMAND', 'Требуется подтверждённый расчёт.', 422);
        return $service->execute($user, $body['request_key'], $input, $body['quote_id'], $body['expected_revisions']);
    }
    public function actionEquipmentExpandPreview($id): array { return $this->equipmentExpansionCommand($id, true); }
    public function actionEquipmentExpand($id): array { return $this->equipmentExpansionCommand($id, false); }
    private function gardenCommand($id, string $operation, bool $preview): array
    {
        if (!Yii::$app->request->isPost) throw new \yii\web\MethodNotAllowedHttpException('Используйте POST.');
        $body = Yii::$app->request->bodyParams;
        if (!is_array($body)) throw new GameError('INVALID_COMMAND', 'Некорректное действие с огородом.', 422);
        $service = $this->gardenService(); $user = (int)Yii::$app->user->id;
        $input = ['node_id' => $this->id($id)] + $service->input($body, $operation);
        if ($preview) return $service->preview($user, $input, $operation);
        if (!is_string($body['request_key'] ?? null) || !is_string($body['quote_id'] ?? null) || !is_array($body['expected_revisions'] ?? null)) throw new GameError('INVALID_COMMAND', 'Требуется подтверждённый расчёт.', 422);
        return $service->execute($user, $body['request_key'], $input, $body['quote_id'], $body['expected_revisions'], $operation);
    }
    public function actionGardenPreview($id, string $operation): array { return $this->gardenCommand($id, $operation, true); }
    public function actionGardenExecute($id, string $operation): array { return $this->gardenCommand($id, $operation, false); }
    public function actionNights($id): array
    {
        if (!Yii::$app->request->isGet) throw new \yii\web\MethodNotAllowedHttpException('Используйте GET.');
        $outcome = Yii::$app->request->get('outcome', '');
        if (!is_string($outcome)) throw new GameError('INVALID_NIGHT_FILTER', 'Некорректный фильтр ночей.', 422);
        return (new \common\modules\world\service\WorldNights(Yii::$app->db, $this->flags()))->state((int)Yii::$app->user->id, $this->id($id), $this->id(Yii::$app->request->get('page', 1)), $outcome);
    }
    private function shelterCommand($id, string $operation, bool $preview): array
    {
        if (!Yii::$app->request->isPost) throw new \yii\web\MethodNotAllowedHttpException('Используйте POST.');
        $body = Yii::$app->request->bodyParams;
        if (!is_array($body) || !is_bool($body['direct_deploy'] ?? null) || !is_bool($body['end_lodging'] ?? null)) throw new GameError('INVALID_COMMAND', 'Укажите вариант размещения и подтверждение прекращения ночлега.', 422);
        $input = ['node_id' => $this->id($id), 'direct_deploy' => $body['direct_deploy'], 'end_lodging' => $body['end_lodging']];
        $service = new \common\modules\world\service\WorldShelter(Yii::$app->db, $this->flags()); $user = (int)Yii::$app->user->id;
        if ($preview) return $service->preview($user, $input, $operation);
        if (!is_string($body['request_key'] ?? null) || !is_string($body['quote_id'] ?? null) || !is_array($body['expected_revisions'] ?? null)) throw new GameError('INVALID_COMMAND', 'Требуется подтверждённый расчёт.', 422);
        return $service->execute($user, $body['request_key'], $input, $body['quote_id'], $body['expected_revisions'], $operation);
    }
    public function actionShelterPreview($id, string $operation): array { return $this->shelterCommand($id, $operation, true); }
    public function actionShelterExecute($id, string $operation): array { return $this->shelterCommand($id, $operation, false); }
    private function premisesService(): \common\modules\world\service\WorldPremises
    {
        return new \common\modules\world\service\WorldPremises(Yii::$app->db, $this->flags(), $this->policy());
    }
    private function demolitionService(): \common\modules\world\service\WorldDemolition
    {
        return new \common\modules\world\service\WorldDemolition(Yii::$app->db, $this->flags());
    }
    public function actionDemolition($id): array
    {
        if (!Yii::$app->request->isGet) throw new \yii\web\MethodNotAllowedHttpException('Используйте GET.');
        return $this->demolitionService()->state((int)Yii::$app->user->id, $this->id($id));
    }
    public function actionDemolitions($id): array
    {
        if (!Yii::$app->request->isGet) throw new \yii\web\MethodNotAllowedHttpException('Используйте GET.');
        return $this->demolitionService()->history((int)Yii::$app->user->id, $this->id($id), $this->id(Yii::$app->request->get('page', 1)), $this->orderSearch());
    }
    private function demolitionCommand($id, bool $preview): array
    {
        if (!Yii::$app->request->isPost) throw new \yii\web\MethodNotAllowedHttpException('Используйте POST.');
        $body = Yii::$app->request->bodyParams; $input = ['node_id' => $this->id($id)]; $user = (int)Yii::$app->user->id; $service = $this->demolitionService();
        if ($preview) return $service->preview($user, $input);
        if (!is_array($body) || !is_string($body['request_key'] ?? null) || !is_string($body['quote_id'] ?? null) || !is_array($body['expected_revisions'] ?? null)) throw new GameError('INVALID_COMMAND', 'Требуется подтверждённый расчёт.', 422);
        return $service->execute($user, $body['request_key'], $input, $body['quote_id'], $body['expected_revisions']);
    }
    public function actionDemolishPreview($id): array { return $this->demolitionCommand($id, true); }
    public function actionDemolish($id): array { return $this->demolitionCommand($id, false); }
    private function repairContractService(): \common\modules\world\service\WorldRepairContracts
    {
        return new \common\modules\world\service\WorldRepairContracts(Yii::$app->db, $this->flags());
    }
    public function actionRepairContracts($id): array
    {
        if (!Yii::$app->request->isGet) throw new \yii\web\MethodNotAllowedHttpException('Используйте GET.');
        return $this->repairContractService()->listing((int)Yii::$app->user->id, $this->id($id), $this->id(Yii::$app->request->get('page', 1)), $this->orderSearch());
    }
    private function repairContractCommand($id, bool $preview): array
    {
        if (!Yii::$app->request->isPost) throw new \yii\web\MethodNotAllowedHttpException('Используйте POST.');
        $body = Yii::$app->request->bodyParams;
        if (!is_array($body)) throw new GameError('INVALID_COMMAND', 'Некорректные условия договора.', 422);
        $input = ['node_id' => $this->id($id), 'offer_id' => $this->id($body['offer_id'] ?? null)]; $user = (int)Yii::$app->user->id; $service = $this->repairContractService();
        if ($preview) return $service->preview($user, $input);
        if (!is_string($body['request_key'] ?? null) || !is_string($body['quote_id'] ?? null) || !is_array($body['expected_revisions'] ?? null)) throw new GameError('INVALID_COMMAND', 'Требуется подтверждённый расчёт.', 422);
        return $service->execute($user, $body['request_key'], $input, $body['quote_id'], $body['expected_revisions']);
    }
    public function actionRepairContractPreview($id): array { return $this->repairContractCommand($id, true); }
    public function actionRepairContractExecute($id): array { return $this->repairContractCommand($id, false); }
    private function buildingRepairService(): \common\modules\world\service\WorldBuildingRepair
    {
        return new \common\modules\world\service\WorldBuildingRepair(Yii::$app->db, $this->flags());
    }
    public function actionBuildingRepair($id): array
    {
        if (!Yii::$app->request->isGet) throw new \yii\web\MethodNotAllowedHttpException('Используйте GET.');
        return $this->buildingRepairService()->state((int)Yii::$app->user->id, $this->id($id));
    }
    private function buildingRepairCommand($id, bool $preview): array
    {
        if (!Yii::$app->request->isPost) throw new \yii\web\MethodNotAllowedHttpException('Используйте POST.');
        $body = Yii::$app->request->bodyParams; $input = ['node_id' => $this->id($id)]; $user = (int)Yii::$app->user->id; $service = $this->buildingRepairService();
        if ($preview) return $service->preview($user, $input);
        if (!is_array($body) || !is_string($body['request_key'] ?? null) || !is_string($body['quote_id'] ?? null) || !is_array($body['expected_revisions'] ?? null)) throw new GameError('INVALID_COMMAND', 'Требуется подтверждённый расчёт.', 422);
        return $service->execute($user, $body['request_key'], $input, $body['quote_id'], $body['expected_revisions']);
    }
    public function actionBuildingRepairPreview($id): array { return $this->buildingRepairCommand($id, true); }
    public function actionBuildingRepairExecute($id): array { return $this->buildingRepairCommand($id, false); }
    private function buildingOperationService(): \common\modules\world\service\WorldBuildingOperation
    {
        return new \common\modules\world\service\WorldBuildingOperation(Yii::$app->db, $this->flags());
    }
    public function actionBuildingOperation($id): array
    {
        if (!Yii::$app->request->isGet) throw new \yii\web\MethodNotAllowedHttpException('Используйте GET.');
        return $this->buildingOperationService()->state((int)Yii::$app->user->id, $this->id($id));
    }
    private function buildingOperationCommand($id, string $operation, bool $preview): array
    {
        if (!Yii::$app->request->isPost) throw new \yii\web\MethodNotAllowedHttpException('Используйте POST.');
        $body = Yii::$app->request->bodyParams; $input = ['node_id' => $this->id($id)]; $user = (int)Yii::$app->user->id; $service = $this->buildingOperationService();
        if ($preview) return $service->preview($user, $input, $operation);
        if (!is_array($body) || !is_string($body['request_key'] ?? null) || !is_string($body['quote_id'] ?? null) || !is_array($body['expected_revisions'] ?? null)) throw new GameError('INVALID_COMMAND', 'Требуется подтверждённый расчёт.', 422);
        return $service->execute($user, $body['request_key'], $input, $body['quote_id'], $body['expected_revisions'], $operation);
    }
    public function actionBuildingOperationPreview($id, string $operation): array { return $this->buildingOperationCommand($id, $operation, true); }
    public function actionBuildingOperationExecute($id, string $operation): array { return $this->buildingOperationCommand($id, $operation, false); }
    private function constructionService(): \common\modules\world\service\WorldConstruction
    {
        return new \common\modules\world\service\WorldConstruction(Yii::$app->db, $this->flags());
    }
    public function actionConstruction($id): array
    {
        if (!Yii::$app->request->isGet) throw new \yii\web\MethodNotAllowedHttpException('Используйте GET.');
        $status = Yii::$app->request->get('status', '');
        if (!is_string($status)) throw new GameError('INVALID_FILTER', 'Некорректный фильтр.', 422);
        return $this->constructionService()->listing((int)Yii::$app->user->id, $this->id($id), $this->id(Yii::$app->request->get('page', 1)), $this->orderSearch(), $status);
    }
    private function constructionCommand($id, string $operation, bool $preview): array
    {
        if (!Yii::$app->request->isPost) throw new \yii\web\MethodNotAllowedHttpException('Используйте POST.');
        $body = Yii::$app->request->bodyParams; $input = ['node_id' => $this->id($id)]; $user = (int)Yii::$app->user->id; $service = $this->constructionService();
        if ($preview) return $service->preview($user, $input, $operation);
        if (!is_array($body) || !is_string($body['request_key'] ?? null) || !is_string($body['quote_id'] ?? null) || !is_array($body['expected_revisions'] ?? null)) throw new GameError('INVALID_COMMAND', 'Требуется подтверждённый расчёт.', 422);
        return $service->execute($user, $body['request_key'], $input, $body['quote_id'], $body['expected_revisions'], $operation);
    }
    public function actionConstructionPreview($id, string $operation): array { return $this->constructionCommand($id, $operation, true); }
    public function actionConstructionExecute($id, string $operation): array { return $this->constructionCommand($id, $operation, false); }
    public function actionPremises($id): array
    {
        if (!Yii::$app->request->isGet) throw new \yii\web\MethodNotAllowedHttpException('Используйте GET.');
        return $this->premisesService()->listing((int)Yii::$app->user->id, $this->id($id), $this->id(Yii::$app->request->get('page', 1)), $this->orderSearch());
    }
    private function premisesCommand($id, string $action, bool $preview): array
    {
        if (!Yii::$app->request->isPost) throw new \yii\web\MethodNotAllowedHttpException('Используйте POST.');
        $body = Yii::$app->request->bodyParams;
        if (!is_array($body)) throw new GameError('INVALID_COMMAND', 'Некорректное действие.', 422);
        $service = $this->premisesService(); $input = ['node_id' => $this->id($id)];
        if ($action === 'publish') $input += $service->publication($body);
        else $input['offer_id'] = $this->id($body['offer_id'] ?? null);
        $user = (int)Yii::$app->user->id;
        if ($preview) return $service->preview($user, $input, $action);
        if (!is_string($body['request_key'] ?? null) || !is_string($body['quote_id'] ?? null) || !is_array($body['expected_revisions'] ?? null)) throw new GameError('INVALID_COMMAND', 'Требуется подтверждённый расчёт.', 422);
        return $service->execute($user, $body['request_key'], $input, $body['quote_id'], $body['expected_revisions'], $action);
    }
    public function actionPremisesPublishPreview($id): array { return $this->premisesCommand($id, 'publish', true); }
    public function actionPremisesPublish($id): array { return $this->premisesCommand($id, 'publish', false); }
    public function actionPremisesBuyPreview($id): array { return $this->premisesCommand($id, 'buy', true); }
    public function actionPremisesBuy($id): array { return $this->premisesCommand($id, 'buy', false); }
    public function actionPremisesWithdrawPreview($id): array { return $this->premisesCommand($id, 'withdraw', true); }
    public function actionPremisesWithdraw($id): array { return $this->premisesCommand($id, 'withdraw', false); }
    public function actionJoinPreview(): array
    {
        $body = Yii::$app->request->bodyParams;
        if (!is_array($body)) throw new GameError('INVALID_COMMAND', 'Некорректное действие.', 422);
        return (new WorldOnboarding(Yii::$app->db, $this->flags()))->preview((int)Yii::$app->user->id, $this->id($body['settlement_id'] ?? null));
    }
    public function actionJoin(): array
    {
        $body = Yii::$app->request->bodyParams;
        if (!is_array($body) || !is_string($body['request_key'] ?? null) || !is_string($body['quote_id'] ?? null) || !is_array($body['expected_revisions'] ?? null)) throw new GameError('INVALID_COMMAND', 'Требуется подтверждённый расчёт.', 422);
        return (new WorldOnboarding(Yii::$app->db, $this->flags()))->join((int)Yii::$app->user->id, $body['request_key'], $this->id($body['settlement_id'] ?? null), $body['quote_id'], $body['expected_revisions']);
    }
    private function management($id, bool $move, bool $preview): array
    {
        if ($preview) $this->flags()->requireFlag('world_read');
        $policy = $this->policy(); $policy->requireAdmin();
        $body = Yii::$app->request->bodyParams;
        if (!is_array($body) || !is_string($body['reason'] ?? null) || trim($body['reason']) === '' || mb_strlen($body['reason'], 'UTF-8') > 255) throw new GameError('REASON_REQUIRED', 'Укажите причину изменения.', 422);
        $payload = ['node_id' => $this->id($id), 'reason' => trim($body['reason'])];
        if ($move) $payload['parent_id'] = $this->id($body['parent_id'] ?? null);
        $type = $move ? 'world.node.move' : 'world.node.archive';
        $bus = new CommandBus(Yii::$app->db, $this->flags()); $tree = new WorldTree(Yii::$app->db);
        if ($preview) return $bus->preview((int)Yii::$app->user->id, $type, $payload, function (array $input) use ($tree, $move) {
            $node = $tree->get($input['node_id']); $revisions = ['node:' . $node['id'] => (int)$node['revision']];
            if ($move) { $prepared = $tree->previewMove($input['node_id'], $input['parent_id']); $parent = $prepared['parent']; $revisions['node:' . $parent['id']] = (int)$parent['revision']; }
            else $tree->previewArchive($input['node_id']);
            return ['revisions' => $revisions, 'terms' => ['node_id' => (int)$node['id'], 'action' => $move ? 'move' : 'archive']];
        });
        if (!is_string($body['request_key'] ?? null) || !is_string($body['quote_id'] ?? null) || !is_array($body['expected_revisions'] ?? null)) throw new GameError('INVALID_COMMAND', 'Требуется подтверждённый расчёт.', 422);
        return $bus->execute((int)Yii::$app->user->id, $body['request_key'], $type, $payload, $body['quote_id'], $body['expected_revisions'], function (array $input, array $terms, string $operation) use ($tree, $move, $bus, $type) {
            $before = $tree->get($input['node_id']);
            $after = $move ? $tree->move($input['node_id'], $input['parent_id']) : $tree->archive($input['node_id']);
            $tree->audit((int)Yii::$app->user->id, $type, $input['reason'], $before, $after, $operation);
            $bus->emit($operation, (int)Yii::$app->user->id, $type, ['node_id' => (int)$after['id'], 'revision' => (int)$after['revision']]);
            return ['node' => (new WorldQuery(Yii::$app->db, $this->policy()))->node((int)$after['id']), 'changed_node_ids' => array_values(array_unique(array_filter([(int)$after['id'], (int)$before['parent_id'], (int)$after['parent_id']])) )];
        });
    }
    public function actionMovePreview($id): array { return $this->management($id, true, true); }
    public function actionMove($id): array { return $this->management($id, true, false); }
    public function actionArchivePreview($id): array { return $this->management($id, false, true); }
    public function actionArchive($id): array { return $this->management($id, false, false); }
}
