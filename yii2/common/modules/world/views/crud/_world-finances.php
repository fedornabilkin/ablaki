<?php
use yii\helpers\Html;
$money = static function ($value) { $value = (string)$value; if (strpos($value, '.') !== false) $value = rtrim(rtrim($value, '0'), '.'); return '<i class="fa fa-money" aria-hidden="true"></i> ' . Html::encode($value ?: '0'); };
$labels = ['budget' => 'Бюджеты', 'treasury' => 'Казны', 'loss' => 'Учтённые потери', 'system' => 'Системные счета'];
?>
<section class="box box-primary" aria-label="Средства мира"><div class="box-header"><h2 class="box-title">Финансовая картина мира</h2></div><div class="box-body">
<p>Участников в расчёте: <strong><?= $financialOverview['participants'] ?></strong>. Профиль <?= Html::encode($financialOverview['profile_version']) ?>. Резервы объектов уже входят в остатки счетов; повторно к сумме не прибавляются.</p>
<p>Скорость времени: <strong>×<?= \common\modules\world\models\WorldTime::rate($financialOverview['world_id']) ?></strong>. <?= Html::a('Настроить время мира', ['update', 'id' => $financialOverview['world_id']], ['class' => 'btn btn-default btn-sm']) ?></p>
<?php if ($npc = $financialOverview['npc'] ?? null): ?>
<section class="well well-sm"><h3>Стартовая инфраструктура NPC bot</h3>
<p><?= Html::a('Стоянка в Северограде', ['view', 'id' => $npc['site_node_id']]) ?> · Оплата партии: <?= $money($npc['reward_cr']) ?> · Свободный бюджет каждого объекта: <?= $money($npc['target_budget_cr']) ?>.</p>
<p>На балансе bot: <?= $money($npc['wallet_cr']) ?> · Свободно в бюджетах NPC и публичных территорий: <?= $money($npc['free_budgets_cr']) ?> · Всего доступно для работы: <strong><?= $money($npc['available_pool_cr']) ?></strong>.</p>
<p>Защищённые обязательства: <?= $money($npc['protected_cr']) ?>. Пополнение каждые <?= (int)$npc['refill_interval_seconds'] ?> с, также после выплаты. Выручка от продаж возвращается в этот оборот. Новые кредиты не выпускаются.</p>
<?php if (!\common\modules\world\modules\economy\value\Money::parse((string)$npc['shortfall_cr'])->isZero()): ?><p class="text-danger">Не хватает для целевых бюджетов: <?= $money($npc['shortfall_cr']) ?>. Нужен перевод существующих средств в бюджет мира или на баланс bot.</p><?php endif ?>
</section><?php endif ?>
<div class="row"><div class="col-md-6">
<?php if ($financialOverview['starter_settlements']): ?>
<h3>Стартовые средства и места для заселения</h3>
<p>Это отдельный защищённый резерв внутри бюджета. Пополнение уменьшает свободный остаток; сумма не прибавляется к общим деньгам повторно. Принятые обязательства оплачиваются по прежней ставке. Новая ставка действует для следующих поселенцев.</p>
<?php foreach ($financialOverview['starter_settlements'] as $city): ?>
<section class="well well-sm"><strong><?= Html::encode($city['name']) ?></strong>
<p>Свободный бюджет: <?= $money($city['available_budget_cr']) ?> · Стартовый резерв: <?= $money($city['reserve_cr']) ?> · Мест: <strong><?= (int)$city['available_places'] ?></strong> · Очередь: <?= (int)$city['waiting'] ?></p>
<?= Html::beginForm(['starter-reserve'], 'post', ['class' => 'form-inline']) ?>
<?= Html::hiddenInput('node_id', $city['node_id']) ?><?= Html::hiddenInput('request_key', bin2hex(random_bytes(16))) ?>
<label>Новому поселенцу <?= Html::textInput('payout_cr', $city['amount_cr'], ['class' => 'form-control', 'inputmode' => 'decimal', 'required' => true, 'style' => 'width:110px']) ?></label>
<label>Добавить из бюджета в резерв <?= Html::textInput('add_amount_cr', '0', ['class' => 'form-control', 'inputmode' => 'decimal', 'required' => true, 'style' => 'width:110px']) ?></label>
<?= Html::submitButton('Сохранить резерв', ['class' => 'btn btn-primary']) ?><?= Html::endForm() ?>
</section><?php endforeach ?><?php endif ?>
<h3>Базовый план старта</h3><p>Расчёт по базовой ставке профиля. Фактические ставки поселений и число обеспеченных мест показаны выше.</p><table class="table table-condensed"><thead><tr><th>Назначение</th><th>На участника</th><th>На группу</th></tr></thead><tbody>
<?php foreach (['starter' => 'Стартовые переводы', 'orders' => 'Конечные заказы', 'goals' => 'Награды целей'] as $key => $label): ?>
<tr><td><?= $label ?></td><td><?= $money($financialOverview[$key . '_per_user_cr']) ?></td><td><?= $money($financialOverview[$key . '_total_cr']) ?></td></tr>
<?php endforeach ?><tr><th>Всего обеспечить</th><td></td><th><?= $money($financialOverview['required_total_cr']) ?></th></tr></tbody></table>
<p>Из этого резерв бюджета поселений: <?= $money($financialOverview['required_budget_cr']) ?>. Это расчёт, а не начисление новых кредитов.</p>
<?php if ($financialOverview['starter_funding_mode'] === 'parent_budget'): ?>
<p>При первом размещении стоянки стартовые средства переводятся из защищённого стартового резерва поселения в бюджет стоянки. При нехватке полная сумма выплачивается в порядке очереди после пополнения резерва. Обычного пополнения свободного бюджета недостаточно — выделите сумму в стартовый резерв.</p>
<p>Уже профинансировано стоянок: <?= $money($financialOverview['paid_starter_cr']) ?>. Ожидают финансирования: <?= $money($financialOverview['pending_starter_cr']) ?>.</p>
<details><summary>Очередь стартового финансирования</summary><table class="table table-condensed"><tr><th>Очередь</th><th>Участник</th><th>Родитель</th><th>Стоянка</th><th>Сумма</th><th>Статус</th></tr><?php foreach ($financialOverview['starter_obligations'] as $row): ?><tr><td><?= (int)$row['id'] ?></td><td>№<?= (int)$row['user_id'] ?></td><td><?= Html::a('№' . $row['source_node_id'], ['view', 'id' => $row['source_node_id']]) ?></td><td><?= Html::a('№' . $row['destination_node_id'], ['view', 'id' => $row['destination_node_id']]) ?></td><td><?= $money($row['amount']) ?></td><td><?= Html::encode(['paid' => 'Переведено', 'pending' => 'Ожидает', 'cancelled' => 'Отменено'][$row['status']] ?? $row['status']) ?></td></tr><?php endforeach ?></table></details>
<?php endif ?>
<p>В мире уже оплачены заказы: <?= $money($financialOverview['paid_orders_cr']) ?>; выданы награды: <?= $money($financialOverview['paid_goals_cr']) ?>. Неоплаченная часть начального бюджетного плана: <?= $money($financialOverview['remaining_budget_cr']) ?>. Выплаченные средства не требуют повторного обеспечения.</p>
<table class="table table-condensed"><tr><td>Уже зарезервировано на старт</td><td><?= $money($financialOverview['funded_starter_cr']) ?></td></tr><tr><td>Уже зарезервировано на заказы</td><td><?= $money($financialOverview['funded_orders_cr']) ?></td></tr><tr><td>Уже зарезервировано на награды</td><td><?= $money($financialOverview['funded_goals_cr']) ?></td></tr><tr><td>Свободные бюджеты поселений</td><td><?= $money($financialOverview['free_settlement_cr']) ?></td></tr><tr><th>Не хватает для обеспечения бюджета</th><th><?= $money($financialOverview['budget_shortfall_cr']) ?></th></tr></table>
</div><div class="col-md-6"><h3>Фактические средства</h3><table class="table table-condensed"><tr><td>Личные кошельки всех пользователей сайта</td><td><?= $money($financialOverview['wallets_cr']) ?></td></tr>
<?php foreach ($financialOverview['roles'] as $role => $totals): ?><tr><td><?= Html::encode($labels[$role] ?? $role) ?></td><td><?= $money($totals['amount_cr']) ?> <small>(резерв <?= $money($totals['reserved_cr']) ?>; доступно <?= $money($totals['available_cr']) ?>)</small></td></tr><?php endforeach ?>
<tr><td>Неполученные переводы</td><td><?= $money($financialOverview['transfer_reserve_cr']) ?></td></tr><tr><td>Кредиты в открытых заявках биржи</td><td><?= $money($financialOverview['exchange_reserve_cr']) ?></td></tr><tr><th>Учтено: кошельки, счета мира и отдельные резервы</th><th><?= $money($financialOverview['total_cr']) ?></th></tr></table>
<p>Личные кошельки общие для сайта. Счета показаны для действующего мира. Это снимок остатков, не доказательство эмиссии или глобальной сохранности; неизвестный тип старой заявки биржи не позволяет определить её резерв.</p></div></div>
<details><summary>Состав оплаченного спроса</summary><table class="table table-condensed"><tr><th>Предмет</th><th>На участника</th><th>Всего</th><th>Цена</th><th>Резерв</th></tr><?php foreach ($financialOverview['order_details'] as $row): ?><tr><td><?= Html::encode($row['code']) ?></td><td><?= $row['quantity_per_user'] ?></td><td><?= $row['quantity'] ?></td><td><?= $money($row['unit_price_cr']) ?></td><td><?= $money($row['total_cr']) ?></td></tr><?php endforeach ?></table></details>
<details><summary>Кошельки пользователей</summary><table class="table table-condensed"><tr><th>Пользователь</th><th>Остаток</th></tr><?php foreach ($financialOverview['users'] as $row): ?><tr><td><?= Html::encode($row['username']) ?> · №<?= (int)$row['id'] ?></td><td><?= $money($row['credit']) ?></td></tr><?php endforeach ?></table></details>
<details><summary>Бюджеты и казны объектов</summary><table class="table table-condensed"><tr><th>Объект</th><th>Счёт</th><th>Всего</th><th>Резерв</th><th>Доступно</th></tr><?php foreach ($financialOverview['accounts'] as $row): ?><tr><td><?= Html::a($row['node_name'], ['view', 'id' => $row['node_id']]) ?></td><td><?= Html::encode($labels[$row['role']] ?? $row['role']) ?></td><td><?= $money($row['amount']) ?></td><td><?= $money($row['reserved']) ?></td><td><?= $money($row['available_cr']) ?></td></tr><?php endforeach ?></table></details>
</div></section>
