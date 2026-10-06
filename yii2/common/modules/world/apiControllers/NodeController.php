<?php
namespace common\modules\world\apiControllers;

use common\modules\world\models\domain\WorldQuery;
use common\modules\world\models\domain\WorldTree;
use common\modules\world\support\CommandBus;
use common\modules\world\support\GameError;
use Yii;

class NodeController extends LegacyController
{
    public function actionIndex(): array
    {
        if (Yii::$app->request->get('view') === 'home') return $this->page(null);
        $capabilities = $this->flags()->capabilities();
        $result = ['contract_version' => 1, 'server_time' => time(), 'capabilities' => $capabilities, 'world' => null, 'regions' => ['items' => [], '_meta' => ['totalCount' => 0, 'pageCount' => 0, 'currentPage' => 1, 'perPage' => 20]]];
        if (!$capabilities['world_read']) return $result;
        $registry = \common\modules\world\models\Registry::active();
        if (!$registry['active_world_id']) return $result;
        $reader = $this->reader(); $id = (int)$registry['active_world_id'];
        $result['home_node_id'] = \common\modules\world\models\domain\WorldHome::node(Yii::$app->db, (int)Yii::$app->user->id, $id);
        $result['world'] = $reader->node($id); $result['regions'] = $reader->children($id, Yii::$app->request->queryParams);
        return $result;
    }
    private function campsiteActions(array $node): ?array
    {
        if ($node['type'] !== 'PLOT' || (((array)$node['details'])['plot_kind'] ?? null) !== 'campsite' || !$node['permissions']['storage']) return null;
        $user = (int)Yii::$app->user->id;
        if (!\common\modules\world\models\Membership::ownsSite($user, (int)$node['root_id'], (int)$node['id'])) return null;
        return (new \common\modules\world\models\domain\WorldCampsite(Yii::$app->db, $this->flags()))->actions($user, (int)$node['id']);
    }
    public function actionNode($id): array
    {
        $node = $this->reader()->node($this->id($id)); $actions = $this->campsiteActions($node);
        if ($actions) $node['actions'] = $actions['items'];
        return $node;
    }
    public function actionChildren($id): array { return $this->reader()->children($this->id($id), Yii::$app->request->queryParams); }
    public function actionNavigation($id): array
    {
        $node = $this->id($id);
        return Yii::$app->request->get('include') === 'map' ? $this->page($node) : $this->reader()->navigation($node);
    }
    /** An opt-in page envelope keeps older navigation/root clients compatible. */
    private function page(?int $id): array
    {
        return (new \common\modules\world\modules\economy\models\domain\FinanceReadSnapshot(Yii::$app->db))->run(function () use ($id): array {
            $capabilities = $this->flags()->capabilities();
            $result = ['contract_version' => 1, 'server_time' => time(), 'capabilities' => $capabilities, 'navigation' => null, 'map' => null];
            if (!$capabilities['world_read']) return $result;
            if ($id === null) {
                $world = \common\modules\world\models\Registry::worldId();
                if (!$world) return $result;
                $id = \common\modules\world\models\domain\WorldHome::node(Yii::$app->db, (int)Yii::$app->user->id, (int)$world) ?? (int)$world;
            }
            return array_replace($result, (new WorldQuery(Yii::$app->db, $this->policy()))->page($id));
        });
    }
    public function actionMap($id): array { return $this->reader()->map($this->id($id)); }
    private function mapCellCommand($id, string $action, bool $preview): array
    {
        if (!Yii::$app->request->isPost) throw new \yii\web\MethodNotAllowedHttpException('Используйте POST.');
        $body = Yii::$app->request->bodyParams;
        if (!is_array($body)) throw new GameError('INVALID_COMMAND', 'Укажите ячейку карты.', 422);
        $service = new \common\modules\world\models\domain\WorldMapCells(Yii::$app->db, $this->flags(), $this->policy());
        $input = $service->input($this->id($id), $body); $user = (int)Yii::$app->user->id;
        if ($preview) return $service->preview($user, $input, $action);
        if (!is_string($body['request_key'] ?? null) || !is_string($body['quote_id'] ?? null) || !is_array($body['expected_revisions'] ?? null))
            throw new GameError('INVALID_COMMAND', 'Требуется подтверждённый расчёт.', 422);
        return $service->execute($user, $body['request_key'], $input, $body['quote_id'], $body['expected_revisions'], $action);
    }
    public function actionMapExplorePreview($id): array { return $this->mapCellCommand($id, 'explore', true); }
    public function actionMapExplore($id): array { return $this->mapCellCommand($id, 'explore', false); }
    public function actionMapBuyPreview($id): array { return $this->mapCellCommand($id, 'buy', true); }
    public function actionMapBuy($id): array { return $this->mapCellCommand($id, 'buy', false); }
    public function actionStatistics($id): array { return ['items' => $this->reader()->statistics($this->id($id))]; }
    public function actionActions($id): array
    {
        $node = $this->reader()->node($this->id($id));
        return $this->campsiteActions($node) ?: ['items' => $node['actions']];
    }
    public function actionCampsite($id): array
    {
        return (new \common\modules\world\models\domain\WorldCampsite(Yii::$app->db, $this->flags()))->state((int)Yii::$app->user->id, $this->id($id));
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
