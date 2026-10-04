<?php
// Reuses the guarded disposable world fixture, including all prior gameplay assertions.
require __DIR__ . '/world-forum80.php';
use backend\components\WorldEntityCatalog;
use backend\components\WorldRelations;
use common\modules\world\model\WorldNodeForm;
use common\modules\world\service\WorldNodeEditor;
use yii\db\Query;

Yii::setAlias('@backend', $yiiRoot . '/backend');
Yii::setAlias('@app', $yiiRoot . '/backend');
Yii::setAlias('@webroot', $yiiRoot . '/backend/web');
Yii::setAlias('@web', '');
$app->controllerNamespace = 'backend\\controllers';
$app->setViewPath($yiiRoot . '/backend/views');
$app->controllerMap = WorldEntityCatalog::controllers();
$app->layout = false;
$app->language = 'ru-RU';
$app->set('view', ['class' => yii\web\View::class, 'theme' => ['pathMap' => ['@app/views' => '@app/views/admin']]]);
$app->set('assetManager', ['class' => yii\web\AssetManager::class, 'bundles' => false]);
$app->urlManager->enableStrictParsing = false;
class WorldAdminTestAuth extends yii\rbac\PhpManager {
    public $denied = [];
    public function checkAccess($userId, $permissionName, $params = []) { return $userId === 9001 && strpos($permissionName, '*') === false && !in_array($permissionName, $this->denied, true); }
}
class WorldAdminTestSession extends yii\web\Session {
    private $values = [];
    public function open() {}
    public function getIsActive() { return true; }
    public function get($key, $defaultValue = null) { return $this->values[$key] ?? $defaultValue; }
    public function set($key, $value) { $this->values[$key] = $value; }
    public function setFlash($key, $value = true, $removeAfterAccess = true) { $this->set($key, $value); }
}
$app->set('session', new WorldAdminTestSession());
$app->set('authManager', new WorldAdminTestAuth(['itemFile' => '@runtime/nonexistent-world-test-items.php', 'assignmentFile' => '@runtime/nonexistent-world-test-assignments.php', 'ruleFile' => '@runtime/nonexistent-world-test-rules.php']));
\mdm\admin\components\Configs::instance()->strict = true;
$app->set('user', ['class' => yii\web\User::class, 'identityClass' => CraftIdentity::class, 'enableSession' => false]);
$app->user->switchIdentity(new CraftIdentity());
$app->request->enableCsrfCookie = false;
$app->attachBehavior('access', ['class' => \mdm\admin\components\AccessControl::class]);
$routeModel = new \mdm\admin\models\Route(); $routeMethod = new ReflectionMethod($routeModel, 'getControllerActions'); $routeMethod->setAccessible(true); $discovered = [];
foreach ($app->controllerMap as $route => $config) $routeMethod->invokeArgs($routeModel, [$config, $route, $app, &$discovered]);
checkCraft(isset($discovered['/world-premises-offer/create'], $discovered['/world-garden-offer/delete'], $discovered['/world-crop-cycle/view']) && !isset($discovered['/world-crop-cycle/update']), 'mdm discovers separate entity routes and applicable actions');
// WorldMigration deliberately omits SQLite FKs. Supply navigation metadata only;
// the same suite uses real database constraints on the MySQL/PostgreSQL CI jobs.
if ($db->driverName === 'sqlite') foreach ([
    'world_crop_revision' => [['world_crop', 'crop_id' => 'id']],
    'world_crop_cycle' => [['world_crop_revision', 'crop_revision_id' => 'id'], ['world_bed', 'bed_id' => 'node_id']],
    'world_premises_offer' => [['world_node', 'settlement_id' => 'id'], ['world_template_revision', 'template_revision_id' => 'id']],
    'world_garden_offer' => [['world_node', 'settlement_id' => 'id']],
    'craft_storage' => [['world_node', 'node_id' => 'id']],
    'craft_inventory' => [['craft_storage', 'storage_id' => 'id']],
    'world_slot' => [['craft_storage', 'storage_id' => 'id']],
    'world_slot_entitlement' => [['world_slot', 'storage_id' => 'storage_id', 'position' => 'position']],
    'world_template_revision' => [['world_template', 'template_id' => 'id']],
] as $table => $foreignKeys) $db->schema->getTableSchema($table)->foreignKeys = $foreignKeys;
// Fail on notices too: templates must not silently omit missing fields.
set_error_handler(static function ($severity, $message, $file, $line) { if (!(error_reporting() & $severity)) return false; throw new ErrorException($message, 0, $severity, $file, $line); });
function adminPage(string $route, array $params = [], array $query = [], ?array $post = null, bool $csrf = true) {
    $app = Yii::$app; $_SERVER['REQUEST_METHOD'] = $post === null ? 'GET' : 'POST';
    $app->request->setUrl('/' . $route);
    $app->request->setQueryParams($query); $app->request->setBodyParams($post ?? []); $app->response->statusCode = 200;
    if ($post !== null && $csrf) $app->request->setBodyParams($post + [$app->request->csrfParam => $app->request->getCsrfToken()]);
    $app->view->params = [];
    return $app->runAction($route, $params);
}
function adminReject(int $status, callable $call, string $label): void {
    try { $call(); } catch (yii\web\HttpException $e) { checkCraft($e->statusCode === $status, $label . ' HTTP ' . $e->statusCode); return; }
    catch (yii\base\InvalidRouteException $e) { checkCraft($status === 404, $label . ' route absent'); return; }
    throw new RuntimeException('Expected rejection: ' . $label);
}
foreach (WorldEntityCatalog::controllers() as $route => $config) {
    $table = $config['table'];
    $html = adminPage($route . '/index');
    checkCraft(strpos($html, 'records-' . $route) !== false, $route . ' list renders');
    $row = (new Query())->from($table)->one($db);
    if ($row) {
        $url = WorldRelations::route($table, $row); $path = ltrim(array_shift($url), '/');
        checkCraft(is_string(adminPage($path, $url)), $route . ' card and relations render');
    }
}
foreach (WorldNodeForm::ROUTES as $type => $route) {
    checkCraft(is_string(adminPage($route . '/index')), $route . ' list');
    $node = (new Query())->from('world_node')->where(['node_type' => $type])->one($db);
    checkCraft(is_string(adminPage($route . '/create')), $route . ' create form');
    if ($node) {
        checkCraft(strpos(adminPage($route . '/view', ['id' => $node['id']]), 'Дочерние объекты') !== false, $route . ' child table');
        checkCraft(is_string(adminPage($route . '/update', ['id' => $node['id']])), $route . ' update form');
    }
}
foreach (['world-crop', 'world-slot', 'world-warehouse'] as $route) checkCraft(is_string(adminPage($route . '/index')), $route . ' list');
$crop = (new Query())->from('world_crop')->one($db);
checkCraft(strpos(adminPage('world-crop/view', ['id' => $crop['id']]), 'world_crop_revision') !== false, 'crop card links revision history');
$storagePolicy = (new Query())->from('world_warehouse_policy')->one($db);
checkCraft(is_string(adminPage('world-warehouse/view', ['id' => $storagePolicy['storage_id']])), 'warehouse card');
$slotEditor = new common\modules\world\service\WorldSlotEditor($db, $flags);
$slotForm = new common\modules\world\model\WorldSlotForm();
$slotForm->populate($slotEditor->state((int)$room['id']), 0); $slotForm->code = 'fixture-slot'; $slotForm->reason = 'Admin slot fixture';
$slotInput = $slotForm->payload(0); $slotQuote = $slotEditor->preview(9001, $slotInput, 'create');
$slotEditor->execute(9001, ['action' => 'create', 'input' => $slotInput, 'quote' => $slotQuote, 'key' => bin2hex(random_bytes(16))]);
$slot = (new Query())->from('world_slot')->one($db); $slotUrl = WorldRelations::route('world_slot', $slot); $slotPath = ltrim(array_shift($slotUrl), '/');
checkCraft(is_string(adminPage($slotPath, $slotUrl)), 'composite slot card');
adminReject(405, static function () { adminPage('world-garden-offer/delete', ['id' => 1]); }, 'delete is POST only');
adminReject(400, static function () { adminPage('world-garden-offer/delete', ['id' => 1], [], ['reason' => 'test'], false); }, 'CSRF required');
adminReject(422, static function () { adminPage('world-map-cell/index', [], ['filter' => ['parent_id' => ['1']]]); }, 'array filter rejected');
adminReject(422, static function () { adminPage('world-map-cell/index', [], ['filter' => ['unknown' => '1']]); }, 'unknown filter rejected');
adminReject(404, static function () { adminPage('world-membership/update', ['id' => 1]); }, 'history has no arbitrary write route');
adminReject(404, static function () { adminPage('world-crop-revision/view', ['id' => 2147483647]); }, 'missing record');
$app->authManager->denied = ['/world-crop-revision/index', '/world-crop-revision/view', '/world-crop/update', '/world-crop/delete']; $app->user->switchIdentity(new CraftIdentity());
$html = adminPage('world-crop/view', ['id' => $crop['id']]);
checkCraft(strpos($html, 'grid-world_crop_revision') === false && strpos($html, '/world-crop/update') === false && strpos($html, '/world-crop/delete') === false, 'cards hide unauthorized relations and actions');
adminReject(403, static function () { adminPage('world-crop-revision/index'); }, 'direct route RBAC');
$app->authManager->denied = []; $app->user->switchIdentity(new CraftIdentity());
$city = (new Query())->from('world_node')->where(['node_type' => 'SETTLEMENT', 'owner_user_id' => null])->one($db);
checkCraft(is_string(adminPage('world-premises-offer/create', [], ['settlement_id' => $city['id']])), 'premises create form');
checkCraft(is_string(adminPage('world-garden-offer/create', [], ['settlement_id' => $city['id']])), 'garden create form');
function adminConfirm(string $route, array $params, array $form): array {
    $html = adminPage($route, $params, [], $form);
    if (!is_string($html) || strpos($html, 'name="quote_id"') === false) throw new RuntimeException('No confirmation: ' . (is_string($html) ? strip_tags($html) : gettype($html)));
    $records = Yii::$app->session->get('world.crud.confirmations'); $record = end($records);
    return ['quote_id' => $record['quote']['quote_id'], 'digest' => $record['digest']];
}
$premisesInput = ['settlement_id' => $city['id'], 'name' => '<b>Test offer</b>', 'kind' => 'house', 'area' => 2, 'slots' => 1, 'price' => '3.0000', 'expansion_limit' => 1, 'reason' => 'Admin test'];
$post = adminConfirm('world-premises-offer/create', [], ['WorldPremisesOfferForm' => $premisesInput]);
$response = adminPage('world-premises-offer/create', [], [], $post);
$offer = (new Query())->from('world_premises_offer')->where(['name' => $premisesInput['name']])->orderBy(['id' => SORT_DESC])->one($db);
checkCraft($response instanceof yii\web\Response && $offer, 'create confirms and persists offer');
adminPage('world-premises-offer/create', [], [], $post);
checkCraft((new Query())->from('world_premises_offer')->where(['name' => $premisesInput['name']])->count('*', $db) == 1, 'repeat confirmation is idempotent');
checkCraft(strpos(adminPage('world-premises-offer/view', ['id' => $offer['id']]), '&lt;b&gt;Test offer&lt;/b&gt;') !== false, 'record names HTML-escaped');
$premisesInput['name'] = 'Replacement'; $premisesInput['price'] = '4.0000';
$post = adminConfirm('world-premises-offer/update', ['id' => $offer['id']], ['WorldPremisesOfferForm' => $premisesInput]);
adminReject(409, static function () use ($post, $offer) { adminPage('world-premises-offer/delete', ['id' => $offer['id']], [], $post); }, 'confirmation bound to action');
adminPage('world-premises-offer/update', ['id' => $offer['id']], [], $post);
checkCraft((new Query())->select('status')->from('world_premises_offer')->where(['id' => $offer['id']])->scalar($db) === 'withdrawn', 'replace withdraws old offer atomically');
checkCraft(json_decode((new Query())->select('config_json')->from('world_template_revision')->where(['id' => $offer['template_revision_id']])->scalar($db), true)['price'] === '3.0000', 'published old terms preserved');
adminReject(409, static function () use ($offer) { adminPage('world-premises-offer/update', ['id' => $offer['id']]); }, 'stale offer cannot be edited');
$newOffer = (new Query())->from('world_premises_offer')->where(['name' => 'Replacement'])->one($db);
$post = adminConfirm('world-premises-offer/delete', ['id' => $newOffer['id']], ['reason' => 'Remove relation']);
adminPage('world-premises-offer/delete', ['id' => $newOffer['id']], [], $post);
checkCraft((new Query())->select('status')->from('world_premises_offer')->where(['id' => $newOffer['id']])->scalar($db) === 'withdrawn', 'delete uses withdrawal');
$gardenService = new common\modules\world\service\WorldGarden($db, $flags, new common\modules\world\service\WorldAccessPolicy(9001, true));
$active = (new Query())->from('world_garden_offer')->where(['active_settlement_id' => $city['id']])->one($db);
if ($active) {
    $input = ['node_id' => (int)$city['id'], 'expected_offer_id' => (int)$active['id']]; $q = $gardenService->preview(9001, $input, 'withdraw');
    $gardenService->execute(9001, bin2hex(random_bytes(16)), $input, $q['quote_id'], (array)$q['expected_revisions'], 'withdraw');
}
$gardenInput = ['settlement_id' => $city['id'], 'name' => 'Admin garden', 'price' => '2.0000', 'base_price' => '1.0000', 'reason' => 'Garden admin test'];
$post = adminConfirm('world-garden-offer/create', [], ['WorldGardenOfferForm' => $gardenInput]);
adminPage('world-garden-offer/create', [], [], $post);
$gardenOffer = (new Query())->from('world_garden_offer')->where(['active_settlement_id' => $city['id']])->one($db);
$post = adminConfirm('world-garden-offer/update', ['id' => $gardenOffer['id']], ['WorldGardenOfferForm' => array_replace($gardenInput, ['price' => '5.0000'])]);
adminPage('world-garden-offer/update', ['id' => $gardenOffer['id']], [], $post);
checkCraft((new Query())->select('active_settlement_id')->from('world_garden_offer')->where(['id' => $gardenOffer['id']])->scalar($db) === null, 'garden update preserves old offer');
$html = adminPage('world-settlement/view', ['id' => $city['id']]);
checkCraft(strpos($html, 'grid-world_premises_offer') !== false && strpos($html, 'grid-world_garden_offer') !== false, 'settlement card includes both offer tables');
checkCraft(WorldRelations::provider('world_premises_offer', [], 'rel-premises')->pagination->pageParam !== WorldRelations::provider('world_garden_offer', [], 'rel-garden')->pagination->pageParam, 'related lists have independent pagination');
// Exercise the existing CRUD archive path which previously dereferenced missing values.
$editor = new WorldNodeEditor($db, $flags); $form = new WorldNodeForm('WORLD');
$form->name = 'Admin temporary world'; $form->code = 'admin-fixture-world'; $form->slug = 'admin-fixture-world'; $form->reason = 'CRUD fixture';
$input = $form->payload(); $quote = $editor->preview(9001, $input, 'create');
$created = $editor->execute(9001, ['action' => 'create', 'input' => $input, 'quote' => $quote, 'key' => bin2hex(random_bytes(16))]);
$node = $editor->snapshot($created['node_id'])['node'];
$input = ['id' => (int)$node['id'], 'revision' => (int)$node['revision'], 'reason' => 'Archive fixture']; $quote = $editor->preview(9001, $input, 'delete');
$editor->execute(9001, ['action' => 'delete', 'input' => $input, 'quote' => $quote, 'key' => bin2hex(random_bytes(16))]);
checkCraft($editor->snapshot($created['node_id'])['node']['status'] === 'archived', 'unused node archives with audit');
echo "PASS world admin entities\n";
