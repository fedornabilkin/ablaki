<?php
namespace common\modules\world\models\domain;

use common\modules\world\models\NodeTemplate;
use common\modules\world\models\Node;
use common\modules\world\support\CanonicalJson;
use common\modules\world\support\GameError;
use yii\db\Query;

final class EconomyConstruction
{
    public static function terms(NodeTemplate $template): ?array
    {
        $revision = EconomyRuntime::current(Node::getDb());
        if (!$revision || !isset($revision['profile']['objects'][$template->code])) return null;
        $price = $revision['profile']['objects'][$template->code]['price_cr'];
        return ['profile_version' => $revision['version'], 'price_cr' => $price,
            'terms_digest' => hash('sha256', CanonicalJson::encode([$revision['id'], $template->getAttributes(), $price]))];
    }
    public static function check(NodeTemplate $template, int $user, array $input, bool $onboarding): ?array
    {
        $db = Node::getDb(); $revision = EconomyRuntime::current($db); if (!$revision) return null;
        $terms = self::terms($template);
        if (!$terms) throw new GameError('TEMPLATE_NOT_IN_PROFILE', 'Объект пока не включён в тестовый каталог.', 422);
        if (!$onboarding && (!is_string($input['terms_digest'] ?? null) || !hash_equals($terms['terms_digest'], $input['terms_digest']))) throw new GameError('BUILD_TERMS_CHANGED', 'Проверьте новую стоимость и условия строительства.', 409, $terms);
        foreach ($revision['profile']['objects'][$template->code]['capability_requirements'] ?? [] as $required) {
            $available = (new Query())->select('n.id')->from(['n' => 'world_node'])->innerJoin(['t' => 'world_template'], 't.id=n.template_id')
                ->where(['n.owner_user_id' => $user, 'n.root_id' => EconomyRuntime::world($db), 'n.status' => 'active', 't.code' => $required])->column($db);
            $active = false;
            foreach ($available as $id) if (NodeAvailability::activePath($db, (int)$id)) { $active = true; break; }
            if (!$active) throw new GameError('BUILD_CAPABILITY_REQUIRED', 'Сначала завершите необходимый объект: ' . $revision['profile']['objects'][$required]['name'], 409);
        }
        $active = (new Query())->from(['b' => 'world_build'])->innerJoin(['n' => 'world_node'], 'n.id=b.node_id')
            ->where(['b.owner_user_id' => $user, 'b.status' => 'building', 'n.root_id' => EconomyRuntime::world($db)])->count('*', $db);
        if ((int)$active >= $revision['profile']['limits']['active_constructions']) throw new GameError('CONSTRUCTION_LIMIT', 'Сначала завершите или отмените одну из трёх строек.', 409);
        $limit = $revision['profile']['limits']['objects'][$template->code] ?? null;
        if ($limit !== null) {
            $count = (new Query())->from('world_node')->where(['owner_user_id' => $user, 'root_id' => EconomyRuntime::world($db), 'template_id' => $template->id])->andWhere(['<>', 'status', 'archived'])->count('*', $db);
            if ((int)$count >= $limit) throw new GameError('OBJECT_LIMIT', 'Достигнут предел объектов этого вида.', 409, ['maximum' => $limit]);
        }
        return $terms;
    }
}
