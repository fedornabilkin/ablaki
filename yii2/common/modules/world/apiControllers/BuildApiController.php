<?php
namespace common\modules\world\apiControllers;

use common\modules\world\models\Build;
use common\modules\world\models\BuildForm;
use common\modules\world\models\BuildWork;
use Yii;

class BuildApiController extends CoreController
{
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
    private function work($id, string $action): array
    {
        $payload = ['build_id' => $this->id($id)];
        return $this->command('world.build.' . $action, $payload, function ($operation) use ($payload, $action) {
            $build = Build::requireOne($payload['build_id']); $user = (int)Yii::$app->user->id;
            if ($action === 'start') return ['work' => BuildWork::start($build, $user), 'build' => $build->toArray()];
            if ($action === 'cancel') {
                $build->node->requireOwner($user); $build->assertOpen(); $build->finish($operation, true);
                return ['build' => $build->toArray()];
            }
            return BuildWork::heartbeat($build, $user, $operation, $action === 'stop');
        });
    }
    public function actionStart($id): array { return $this->work($id, 'start'); }
    public function actionHeartbeat($id): array { return $this->work($id, 'heartbeat'); }
    public function actionStop($id): array { return $this->work($id, 'stop'); }
    public function actionCancel($id): array { return $this->work($id, 'cancel'); }
}
