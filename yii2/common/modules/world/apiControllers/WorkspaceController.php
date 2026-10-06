<?php
namespace common\modules\world\apiControllers;

use common\modules\world\support\GameError;
use Yii;

class WorkspaceController extends LegacyController
{
    public function actionWorkspace(): array
    {
        if (!Yii::$app->request->isGet) throw new \yii\web\MethodNotAllowedHttpException('Используйте GET.');
        $recipe = Yii::$app->request->get('recipe_id');
        return (new \common\modules\world\modules\craft\models\domain\CraftWorkspace(Yii::$app->db, $this->flags()))->state((int)Yii::$app->user->id, $this->id(Yii::$app->request->get('node_id')), $recipe === null ? null : $this->id($recipe));
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
        $service = new \common\modules\world\modules\craft\models\domain\CraftWorkspace(Yii::$app->db, $this->flags()); $user = (int)Yii::$app->user->id;
        if ($preview) return $service->preview($user, $payload);
        if (!is_string($body['request_key'] ?? null) || !is_string($body['quote_id'] ?? null) || !is_array($body['expected_revisions'] ?? null)) throw new GameError('INVALID_COMMAND', 'Требуется подтверждённый расчёт.', 422);
        return $service->craft($user, $body['request_key'], $payload, $body['quote_id'], $body['expected_revisions']);
    }
    public function actionWorkspacePreview(): array { return $this->workspaceCraft(true); }
    public function actionWorkspaceCraft(): array { return $this->workspaceCraft(false); }
    private function supplies($id, string $operation, bool $preview): array
    {
        if (!Yii::$app->request->isPost) throw new \yii\web\MethodNotAllowedHttpException('Используйте POST.');
        $body = Yii::$app->request->bodyParams;
        if (!is_array($body)) throw new GameError('INVALID_COMMAND', 'Некорректное действие.', 422);
        $input = ['node_id' => $this->id($id), 'action' => $operation];
        $service = new \common\modules\world\models\domain\WorldSupplies(Yii::$app->db, $this->flags()); $user = (int)Yii::$app->user->id;
        if ($preview) return $service->preview($user, $input);
        if (!is_string($body['request_key'] ?? null) || !is_string($body['quote_id'] ?? null) || !is_array($body['expected_revisions'] ?? null)) throw new GameError('INVALID_COMMAND', 'Требуется подтверждённый расчёт.', 422);
        return $service->execute($user, $body['request_key'], $input, $body['quote_id'], $body['expected_revisions']);
    }
    public function actionSuppliesPreview($id, string $operation): array { return $this->supplies($id, $operation, true); }
    public function actionSuppliesExecute($id, string $operation): array { return $this->supplies($id, $operation, false); }
}
