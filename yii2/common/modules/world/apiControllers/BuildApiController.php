<?php
namespace common\modules\world\apiControllers;

use common\modules\world\models\Build;
use common\modules\world\models\BuildForm;
use common\modules\world\models\BuildWork;
use Yii;

class BuildApiController extends CoreController
{
    public function actionActive(): array
    {
        $work = BuildWork::find()->alias('w')->innerJoin(['a' => 'world_builder'], 'a.work_id=w.id')
            ->where(['a.user_id' => (int)Yii::$app->user->id, 'w.ended_at' => null])->one();
        return ['build' => $work ? $work->build->toArray() : null];
    }
    public function actionIndex(): array { return $this->listing(Build::listing((int)Yii::$app->user->id, Yii::$app->request->queryParams)); }
    public function actionView($id): array
    {
        return Build::requireOne($this->id($id))->presentation((int)Yii::$app->user->id);
    }
    public function actionCreate(): array
    {
        $form = new BuildForm(); $form->load(Yii::$app->request->bodyParams, ''); $input = $form->input();
        return $this->command('world.build.create', $input, function ($operation) use ($input) {
            $build = Build::begin((int)Yii::$app->user->id, $input, $operation);
            return ['build' => $build->toArray(), 'node' => $build->node->toArray()];
        });
    }
    public function actionSettings($id): array
    {
        $buildId = $this->id($id);
        $form = new \common\modules\world\models\BuildSettingsForm();
        $form->load(Yii::$app->request->bodyParams, ''); $input = $form->input();
        return $this->command('world.build.settings', ['build_id' => $buildId, 'name' => $input['name'], 'labor_budget' => $input['labor_budget'], 'top_up' => $input['top_up']], function ($operation) use ($buildId, $input) {
            return Build::requireOne($buildId)->configure((int)Yii::$app->user->id, $input['name'], $input['labor_budget'], $operation, $input['top_up']);
        });
    }
    public function actionRepair($id): array
    {
        $nodeId = $this->id($id);
        return $this->command('world.repair.create', ['node_id'=>$nodeId], function ($operation) use ($nodeId) {
            $build = Build::repair((int)Yii::$app->user->id, $nodeId, $operation);
            return ['build'=>$build->toArray(),'node'=>$build->node->toArray()];
        });
    }
    private function work($id, string $action): array
    {
        $payload = ['build_id' => $this->id($id)];
        return $this->command('world.build.' . $action, $payload, function ($operation) use ($payload, $action) {
            $build = Build::requireOne($payload['build_id']); $user = (int)Yii::$app->user->id;
            if ($action === 'start') return ['work' => BuildWork::start($build, $user), 'build' => $build->toArray()];
            if ($action === 'cancel') {
                $build->node->requireOwner($user); $build->assertOpen(); $build->finish($operation, true);
                $result = ['build' => $build->toArray()];
                if (!$build->isRepair()) $result['return_node_id'] = (int)$build->parent_id;
                return $result;
            }
            return BuildWork::heartbeat($build, $user, $operation, $action === 'stop');
        });
    }
    public function actionStart($id): array { return $this->work($id, 'start'); }
    public function actionHeartbeat($id): array { return $this->work($id, 'heartbeat'); }
    public function actionStop($id): array { return $this->work($id, 'stop'); }
    public function actionCancel($id): array { return $this->work($id, 'cancel'); }
}
