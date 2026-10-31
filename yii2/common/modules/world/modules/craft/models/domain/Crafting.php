<?php
namespace common\modules\world\modules\craft\models\domain;

use common\modules\world\modules\craft\domain\CraftExperience;
use common\modules\world\modules\craft\domain\CraftCommandInput;
use yii\db\Query;
use yii\web\ConflictHttpException;
use yii\web\UnprocessableEntityHttpException;

class Crafting
{
    private $s;
    public function __construct(CraftStorage $store) { $this->s=$store; }
    private function one(string $table,array $where): ?array { $row=(new Query())->from($table)->where($where)->one($this->s->db); return $row?:null; }
    private function item(int $id,bool $active=true): array { $item=$this->one('craft_item',$active?['id'=>$id,'active'=>1]:['id'=>$id]); if(!$item)throw new ConflictHttpException('Предмет недоступен.'); return $item; }
    private function xp(int $user,int $category,int $add): void
    {
        $where=['user_id'=>$user,'category_id'=>$category]; $row=$this->one('craft_skill',$where);
        if($row) {
            $next = CraftExperience::add((int)$row['experience'], $add);
            if($next!==(int)$row['experience']&&$this->s->db->createCommand()->update('craft_skill',['experience'=>$next],$where)->execute()!==1)throw new \RuntimeException('Skill write failed.');
        } elseif($this->s->db->createCommand()->insert('craft_skill',$where+['experience'=>CraftExperience::add(0, $add)])->execute()!==1)throw new \RuntimeException('Skill write failed.');
    }
    public function requirements(int $user,array $recipe): array
    {
        return $this->requirementsBatch($user, [$recipe])[(int)$recipe['id']];
    }
    /** Read skills and unmet dependencies once for the whole recipe list. */
    public function requirementsBatch(int $user, array $recipes): array
    {
        if (!$recipes) return [];
        $skills = (new Query())->from('craft_skill')->where(['user_id' => $user, 'category_id' => array_values(array_unique(array_column($recipes, 'category_id')))])->indexBy('category_id')->all($this->s->db);
        $reasons = [];
        foreach ($recipes as $recipe) {
            $skill = $skills[$recipe['category_id']] ?? [];
            $reasons[(int)$recipe['id']] = CraftExperience::level((int)($skill['experience'] ?? 0)) < (int)$recipe['min_level'] ? ['Нужен уровень ' . $recipe['min_level']] : [];
        }
        $dependencies = (new Query())->select(['d.recipe_id', 'd.requires_id', 'recipe_name' => 'r.name', 'output_name' => 'i.name', 'output_id' => 'i.id'])->from(['d' => 'craft_dependency'])
            ->leftJoin(['k' => 'craft_known'], '[[k.recipe_id]]=[[d.requires_id]] AND [[k.user_id]]=:requirementUser', [':requirementUser' => $user])
            ->leftJoin(['r' => 'craft_recipe'], '[[r.id]]=[[d.requires_id]]')->leftJoin(['i' => 'craft_item'], '[[i.id]]=[[r.item_id]]')
            ->where(['d.recipe_id' => array_column($recipes, 'id'), 'k.recipe_id' => null])->orderBy(['d.recipe_id' => SORT_ASC, 'd.requires_id' => SORT_ASC])->all($this->s->db);
        $shared = [];
        if ($this->s->workspaceNodeId && (new \common\modules\world\models\domain\NpcInfrastructure($this->s->db))->publicWorkshop($this->s->workspaceNodeId)) {
            $stations = (new Query())->from('craft_station')->where(['id' => array_values(array_filter(array_column($recipes, 'station_id'))), 'active' => 1])->indexBy('id')->all($this->s->db);
            $availableStations = [];
            foreach ($recipes as $recipe) {
                $item = $stations[$recipe['station_id']]['item_id'] ?? null;
                if ($item && !array_key_exists($item, $availableStations)) $availableStations[$item] = (new StationResolver($this->s))->select($user, (int)$item, true) !== null;
                if ($item && $availableStations[$item]) $shared[(int)$recipe['id']] = (int)$item;
            }
        }
        foreach ($dependencies as $dependency) {
            if (($shared[(int)$dependency['recipe_id']] ?? null) === (int)$dependency['output_id']) continue;
            $reasons[(int)$dependency['recipe_id']][] = 'Сначала создайте: ' . trim($dependency['output_name'] ?? preg_replace('/^Создать:\s*/u', '', $dependency['recipe_name'] ?? 'рецепт #' . $dependency['requires_id']));
        }
        return $reasons;
    }
    private function event(int $user,string $action,int $qty,array $extra=[]): void
    {
        $this->s->insert('craft_event',$extra+['user_id'=>$user,'action'=>$action,'quantity'=>$qty,'credit_change'=>0,'created_at'=>time()]);
    }
    public function command(int $user, string $key, string $action, array $payload): array
    {
        try {
            $input = CraftCommandInput::parse($user, $key, $action, $payload);
        } catch (\InvalidArgumentException $error) {
            throw new UnprocessableEntityHttpException($error->getMessage(), 0, $error);
        }
        try {
            $result = $this->s->db->transaction(function () use ($user, $key, $input) {
                // Catalog imports and commands retain the same deterministic lock order.
                $meta = $this->s->lock('craft_meta', ['id' => 1]);
                if ($this->s->isCanonical()) {
                    (new \common\modules\world\support\Locks($this->s->db))->owners([$user]);
                }
                $person = $this->s->lock('persone', ['user_id' => $user]);
                if ($this->s->isCanonical()) {
                    $this->s->lock('world_registry', ['id' => 1]);
                }
                $old = $this->one('craft_command', ['user_id' => $user, 'request_key' => $key]);
                if ($old) {
                    if (!hash_equals($old['fingerprint'], $input->fingerprint)) {
                        throw new ConflictHttpException('Этот ключ уже использован для другой команды.');
                    }
                    return json_decode($old['result'], true) + ['replayed' => true];
                }
                \common\modules\world\models\domain\EconomyRuntime::requireAccess($this->s->db, $user);
                $this->s->operationId = null;
                $this->s->protectedInstances = [];
                StorageMaintenance::writable($this->s->db);
                $inventory = new CraftInventory($this->s);
                $inventory->synchronize($user);
                $recent = (new Query())->from('craft_command')
                    ->where(['user_id' => $user])
                    ->andWhere(['>=', 'created_at', time() - 60])
                    ->count('*', $this->s->db);
                if ((int)$recent >= 30) {
                    throw new \yii\web\TooManyRequestsHttpException('Слишком много операций. Попробуйте через минуту.');
                }
                $result = ['message' => $this->performCommand(
                    $user, $person, $input, $inventory, (int)($meta['charge_credits'] ?? 0) === 1
                )];
                if ($this->s->operationId) {
                    $this->s->db->createCommand()->update('game_operation', [
                        'type' => 'craft.' . $input->action,
                    ], ['id' => $this->s->operationId, 'user_id' => $user, 'type' => 'inventory.legacy'])->execute();
                }
                $this->s->insert('craft_command', [
                    'user_id' => $user,
                    'request_key' => $key,
                    'fingerprint' => $input->fingerprint,
                    'result' => json_encode($result, JSON_UNESCAPED_UNICODE),
                    'created_at' => time(),
                ]);
                return $result + ['replayed' => false];
            });
        } finally {
            $this->s->protectedInstances = [];
        }
        return $result + ['state' => $this->state($user)];
    }

    private function performCommand(
        int $user,
        array $person,
        CraftCommandInput $input,
        CraftInventory $inventory,
        bool $chargeCredits
    ): string {
        $id = $input->itemId;
        $quantity = $input->quantity;
        $action = $input->action;
        if ($action === 'repair') {
            (new ChestRepair($this->s))->repair($user, $input->slotId, $id);
            $this->event($user, 'repair', 1, ['item_id' => $id]);
            return 'Сундук починен.';
        }
        if ($action === 'buy_slots') {
            $cost = $inventory->buy($user, $quantity, $input->unitPrice, $input->totalPrice);
            $this->event($user, $action, $quantity, ['credit_change' => -$cost]);
            return 'Открыто постоянных слотов: ' . $quantity;
        }
        if ($action === 'transfer') {
            $source = $this->one('craft_inventory', ['id' => $input->slotId, 'user_id' => $user, 'item_id' => $id]);
            if (!$source) {
                throw new ConflictHttpException('Предмет в исходном слоте изменился.');
            }
            $moved = $inventory->transfer($user, $input->slotId, $input->containerId, $input->position, $quantity);
            $this->event($user, $action, $moved, ['item_id' => $id]);
            return 'Перемещено предметов: ' . $moved;
        }
        if ($action === 'craft') {
            return $this->craft($user, $person, $id, $quantity, $chargeCredits);
        }
        if ($action === 'starter' || $action === 'gather') {
            return $this->supplies($user, $action);
        }
        if ($action === 'merge') {
            $item = $this->item($id, false);
            $moved = $this->s->merge($user, $item, $input->slotId, $input->targetSlotId);
            $this->event($user, 'merge', $moved, ['item_id' => $id]);
            return 'Объединено: ' . trim($item['name']) . ' × ' . $moved;
        }

        $item = $this->item($id, $action !== 'discard');
        if ($action === 'discard' && !(int)$item['destroyable']) {
            throw new ConflictHttpException('Этот предмет нельзя удалить.');
        }
        $elixir = ($item['storage_kind'] ?? 'none') === 'elixir';
        if ($action === 'use' && (trim($item['kind']) !== 'consumable' || ((int)$item['use_xp'] < 1 && !$elixir))) {
            throw new ConflictHttpException('Этот предмет нельзя использовать.');
        }
        if ($action === 'use' && $elixir) {
            $inventory->unlock($user, $quantity);
        }
        $this->s->move($user, $item, -$quantity, $input->slotId);
        if ($action === 'use') {
            $this->xp($user, (int)$item['category_id'], (int)$item['use_xp'] * $quantity);
        }
        $this->event($user, $action, $quantity, ['item_id' => $id]);
        return ($action === 'use' ? 'Использовано: ' : 'Удалено: ') . trim($item['name']) . ' × ' . $quantity;
    }

    private function supplies(int $user,string $action): string
    {
        if ($action === 'gather' && $this->s->isCanonical()) {
            if (!$this->s->workspaceNodeId) throw new ConflictHttpException('Собирайте сырьё на карте своей стоянки. Откройте «Мой дом» в верхнем меню.');
            return \common\modules\world\models\Supplies::gather((int)$this->s->workspaceNodeId, $user, $this->s->operationId ?? '')['message'];
        }
        try { return (new \common\modules\world\models\domain\SupplyGrant($this->s->db))->grant($this->s, $user, $action); }
        catch (\common\modules\world\support\GameError $error) { throw new ConflictHttpException($error->getMessage()); }
    }
    public function recipeInputs(int $user, int $id, int $qty): array
    {
        if ($qty < 1 || $qty > 100) throw new UnprocessableEntityHttpException('Количество должно быть от 1 до 100.');
        $recipe=$this->one('craft_recipe',['id'=>$id,'active'=>1]); if(!$recipe)throw new ConflictHttpException('Рецепт недоступен.');
        $revision = \common\modules\world\models\domain\EconomyRuntime::current($this->s->db);
        $context = $revision['profile']['recipes'][trim($recipe['code'])]['context'] ?? null;
        if ($context !== null) {
            $location = $this->s->workspaceNodeId ? (new Query())->select('n.*')->from(['n' => 'world_node'])->innerJoin(['t' => 'world_template'], 't.id=n.template_id')
                ->where(['n.id' => $this->s->workspaceNodeId, 'n.status' => 'active', 't.code' => $context])->one($this->s->db) : null;
            $place = $location && ((int)$location['owner_user_id'] === $user || (new \common\modules\world\models\domain\NpcInfrastructure($this->s->db))->publicWorkshop((int)$location['id']));
            if (!$place) throw new \common\modules\world\support\GameError('CRAFT_CONTEXT_REQUIRED', 'Выберите подходящий объект для приготовления: ' . $context, 409);
        }
        foreach (['output_quantity'=>[1,1000],'cost_credits'=>[0,1000000],'experience'=>[0,1000],'min_level'=>[1,100]] as $field=>$bounds) {
            if ((int)$recipe[$field]<$bounds[0]||(int)$recipe[$field]>$bounds[1]) throw new ConflictHttpException('Рецепт требует проверки администратором.');
        }
        $reasons=$this->requirements($user,$recipe); if($reasons)throw new ConflictHttpException(implode('. ',$reasons));
        $output=$this->item((int)$recipe['item_id']);
        $ingredients=$this->s->rows('craft_recipe_item',['recipe_id'=>$id]); if(!$ingredients)throw new ConflictHttpException('У рецепта нет ингредиентов.');
        foreach ($ingredients as $ing) if ((int)$ing['item_quantity']<1||(int)$ing['item_quantity']>10000) throw new ConflictHttpException('Некорректный ингредиент рецепта.');
        $required=[]; $items=[];
        foreach($ingredients as $ing) { $item=$this->item((int)$ing['item_id']); $items[$item['id']]=$item; $required[$item['id']]=($required[$item['id']]??0)+(int)$ing['item_quantity']*$qty; }
        $reserve=[];
        foreach($this->s->rows('craft_recipe_tool',['recipe_id'=>$id]) as $tool)$reserve[(int)$tool['item_id']]=1;
        if($recipe['station_id']!==null) {
            $station=$this->one('craft_station',['id'=>$recipe['station_id'],'active'=>1]); if(!$station)throw new ConflictHttpException('Станция недоступна.');
            if($station['item_id']!==null)$reserve[(int)$station['item_id']]=1;
        }
        return compact('recipe', 'output', 'ingredients', 'required', 'items', 'reserve') + ['station' => $station ?? null];
    }
    private function craft(int $user,array $person,int $id,int $qty,bool $chargeCredits): string
    {
        if ($this->s->isCanonical()) {
            $place = $this->s->workspaceNodeId ? $this->one('world_node', ['id' => $this->s->workspaceNodeId, 'owner_user_id' => $user, 'status' => 'active']) : null;
            if (!$place) throw new ConflictHttpException('Для изготовления выберите свой действующий объект.');
        }
        $input = $this->recipeInputs($user, $id, $qty);
        $recipe = $input['recipe']; $output = $input['output']; $ingredients = $input['ingredients'];
        $required = $input['required']; $items = $input['items']; $reserve = $input['reserve']; $station = $input['station'];
        $selected = [];
        if ($this->s->isCanonical()) {
            $resolver = new StationResolver($this->s);
            foreach ($reserve as $itemId => $count) {
                $isStation = isset($station) && $station['item_id'] !== null && (int)$station['item_id'] === $itemId;
                $unit = $resolver->select($user, $itemId, $isStation);
                if (!$unit) throw new ConflictHttpException($isStation ? 'Нужна доступная размещённая станция с достаточной прочностью.' : 'Нужен исправный инструмент в активном слоте рюкзака.');
                $selected[] = $unit;
                if (!$unit['in_backpack']) unset($reserve[$itemId]);
            }
            $this->s->protectedInstances = array_column($selected, 'instance_id');
        }
        $quantities=$this->s->quantities($user);
        foreach($reserve as $tool=>$count) { $items[$tool]=$this->item($tool); $required[$tool]=($required[$tool]??0)+$count; }
        foreach($required as $item=>$amount) if(($quantities[$item]??0)<$amount)throw new ConflictHttpException('Не хватает: '.trim($items[$item]['name']));
        $cost=$chargeCredits?(int)$recipe['cost_credits']*$qty:0;
        if($cost>0&&(float)$person['credit']<$cost)throw new ConflictHttpException('Не хватает кредитов.');
        if($cost)(new CraftInventory($this->s))->requireTransactionalBalance();
        if ($this->s->isCanonical()) foreach ($selected as $unit) (new EquipmentExposure($this->s->db))->use($unit['instance_id'], 0, $qty, $unit['is_station']);
        foreach($ingredients as $ing)$this->s->move($user,$items[$ing['item_id']],-(int)$ing['item_quantity']*$qty);
        $this->s->move($user,$output,(int)$recipe['output_quantity']*$qty);
        return $this->completeRecipe($user, $recipe, $output, $qty, $cost);
    }
    /** Called only after resource/output writes in the same locked command transaction. */
    public function completeRecipe(int $user, array $recipe, array $output, int $qty, int $cost): string
    {
        if (!$this->s->db->getTransaction()) throw new \LogicException('Recipe completion requires a transaction.');
        $id = (int)$recipe['id'];
        if($cost) {
            (new \common\services\user\CreditLedger($this->s->db))->change($user,-$cost,'craft','Craft #'.$id.' x '.$qty);
        }
        $where=['user_id'=>$user,'recipe_id'=>$id]; $known=$this->one('craft_known',$where);
        $write=$known?$this->s->db->createCommand()->update('craft_known',['quantity'=>(int)$known['quantity']+$qty],$where):$this->s->db->createCommand()->insert('craft_known',$where+['quantity'=>$qty]);
        if($write->execute()!==1)throw new \RuntimeException('Recipe progress write failed.');
        $efficiency = (new \common\modules\world\models\domain\NightWorkEfficiency($this->s->db))->basisPoints($user);
        $this->xp($user, (int)$recipe['category_id'], CraftExperience::recipeAward((int)$recipe['experience'], $qty, $efficiency));
        $this->s->insert('craft_history',['user_id'=>$user,'recipe_id'=>$id,'item_id'=>$output['id'],'created_at'=>time()]);
        $this->event($user,'craft',(int)$recipe['output_quantity']*$qty,['recipe_id'=>$id,'item_id'=>$output['id'],'credit_change'=>-$cost]);
        $module = \Yii::$app->getModule('world');
        if ($module instanceof \common\modules\world\Module) {
            $flags = new \common\modules\world\models\domain\WorldFlags($this->s->db, $module);
            if ($flags->capabilities()['world_write']) {
                (new CraftOperationJournal($this->s))->operation($user, 'craft.completed');
                \common\modules\world\models\domain\EconomyQuota::consume($this->s->db, $user, 'crafting_hourly', $qty, $this->s->operationId);
                \common\modules\world\models\domain\EconomyRuntime::fact($this->s->db, $user, 'crafted:' . trim($recipe['code']), $qty, $this->s->operationId);
                (new \common\modules\world\modules\progression\models\domain\WorldRatingAwards($this->s->db))->award(
                    $user, 'craft', 'craft:' . $this->s->operationId, $this->s->operationId,
                    array_fill(0, $qty, \common\modules\world\modules\progression\domain\WorldRatingPolicy::craft((int)$recipe['experience'])),
                    'Крафт: ' . trim($output['name']) . ' × ' . ((int)$recipe['output_quantity'] * $qty));
                (new \common\modules\world\support\CommandBus($this->s->db, $flags))->emit($this->s->operationId, $user, 'craft.completed', ['node_id' => $this->s->workspaceNodeId, 'recipe_id' => $id, 'batches' => $qty, 'output' => ['item_id' => (int)$output['id'], 'quantity' => (int)$recipe['output_quantity'] * $qty]]);
            }
        }
        return 'Создано: '.trim($output['name']).' × '.((int)$recipe['output_quantity']*$qty);
    }
    public function state(int $user): array
    {
        $s=$this->s; $person=$this->one('persone',['user_id'=>$user]); if(!$person)throw new ConflictHttpException('Профиль недоступен.');
        $items=[]; foreach($s->rows('craft_item') as $r) { foreach(['name','code','kind','rarity','icon','description'] as $field)$r[$field]=trim((string)$r[$field]); $r['label']=trim((string)($r['label']??$r['name'])); foreach(['id','category_id','stack_size','destroyable','use_xp','gather_quantity','active'] as $field)$r[$field]=(int)$r[$field]; $items[]=$r; }
        $known=[]; foreach($s->rows('craft_known',['user_id'=>$user]) as $r)$known[(int)$r['recipe_id']]=(int)$r['quantity'];
        $recipes=[]; foreach($s->rows('craft_recipe',['active'=>1]) as $r) {
            foreach(['id','category_id','item_id','output_quantity','cost_credits','experience','min_level'] as $field)$r[$field]=(int)$r[$field];
            foreach(['name','code','description'] as $field)$r[$field]=trim((string)$r[$field]);
            $r['station_id']=$r['station_id']===null?null:(int)$r['station_id'];
            $r['ingredients']=array_map(static function($v){return ['item_id'=>(int)$v['item_id'],'quantity'=>(int)$v['item_quantity']];},$s->rows('craft_recipe_item',['recipe_id'=>$r['id']]));
            $r['tools']=array_map('intval',array_column($s->rows('craft_recipe_tool',['recipe_id'=>$r['id']]),'item_id'));
            $r['requires']=array_map('intval',array_column($s->rows('craft_dependency',['recipe_id'=>$r['id']]),'requires_id'));
            $r['locked_reasons']=$this->requirements($user,$r); $r['crafted']=$known[$r['id']]??0;
            if ($s->isCanonical()) $r['equipment'] = $this->equipmentState($user, $r);
            $recipes[]=$r;
        }
        $categories=array_map(static function($r){return ['id'=>(int)$r['id'],'name'=>trim($r['name']),'code'=>trim($r['code']),'description'=>trim((string)$r['description'])];},$s->rows('craft_category'));
        $stations=array_map(static function($r){return ['id'=>(int)$r['id'],'name'=>trim($r['name']),'item_id'=>$r['item_id']===null?null:(int)$r['item_id']];},$s->rows('craft_station',['active'=>1]));
        $skills=array_map(static function($r){$xp=(int)$r['experience'];return ['category_id'=>(int)$r['category_id'],'experience'=>$xp,'level'=>CraftExperience::level($xp)];},$s->rows('craft_skill',['user_id'=>$user]));
        $inventory=[]; foreach($s->quantities($user) as $id=>$qty)if($qty>0)$inventory[]=['item_id'=>$id,'quantity'=>$qty];
        $storage=(new CraftInventory($s))->state($user);
        $today=(new \DateTimeImmutable('@'.time()))->setTimezone(new \DateTimeZone('Europe/Moscow'))->setTime(0,0)->getTimestamp();
        $settings=['charge_credits'=>(new CraftSettings($s))->chargeCredits(),'gather_available_at'=>$today+86400];
        $finite = (new \common\modules\world\models\domain\FiniteSupplies($s->db))->state($user);
        if ($finite) $settings = array_intersect_key($finite, array_flip(['gather_available', 'gather_available_at', 'gather_remaining', 'gather_limit', 'gather_exhausted', 'gather_minimum'])) + $settings;
        return $settings+$storage+['items'=>$items,'categories'=>$categories,'recipes'=>$recipes,'stations'=>$stations,'skills'=>$skills,'inventory'=>$inventory,'credit'=>(float)$person['credit'],'starter_available'=>!$this->one('craft_event',['user_id'=>$user,'action'=>'starter']),'gather_available'=>!(new Query())->from('craft_event')->where(['user_id'=>$user,'action'=>'gather'])->andWhere(['>=','created_at',$today])->exists($s->db)];
    }
    private function equipmentState(int $user, array $recipe): array
    {
        $roles = array_fill_keys($recipe['tools'], false);
        $station = $recipe['station_id'] === null ? null : $this->one('craft_station', ['id' => $recipe['station_id'], 'active' => 1]);
        if ($station && $station['item_id'] !== null) $roles[(int)$station['item_id']] = true;
        $result = []; $resolver = new StationResolver($this->s); $wear = new EquipmentExposure($this->s->db);
        foreach ($roles as $item => $isStation) {
            $unit = $resolver->select($user, (int)$item, $isStation);
            $result[] = ['item_id' => (int)$item, 'is_station' => $isStation, 'available' => $unit !== null, 'in_backpack' => $unit ? $unit['in_backpack'] : false,
                'instance_id' => $unit ? $unit['instance_id'] : null, 'durability' => $unit ? $unit['durability'] : 0, 'max_durability' => $unit ? $unit['max_durability'] : 100,
                'wear_per_batch' => $unit ? $wear->cost($unit, 0, 1, $isStation) : 0];
        }
        return $result;
    }
}
