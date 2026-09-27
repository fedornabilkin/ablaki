<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 10.01.2022
 * Time: 23:22
 */

namespace common\modules\exchange\service;

use common\helpers\App;
use common\middleware\AbstractMiddleware;
use common\middleware\person\CheckBalanceMiddleware;
use common\middleware\person\CheckCreditMiddleware;
use common\middleware\person\UpdatePersonMiddleware;
use common\modules\exchange\api\models\CreditExchange;
use common\modules\exchange\exception\CountException;
use common\modules\exchange\middleware\CheckFreeMiddleware;
use common\modules\exchange\middleware\CheckMyMiddleware;
use common\modules\exchange\middleware\exchange\CheckCountMiddleware;
use common\modules\exchange\middleware\exchange\CreateMiddleware;
use common\modules\exchange\middleware\exchange\DeleteMiddleware;
use common\modules\exchange\middleware\exchange\PlayMiddleware;
use common\modules\exchange\middleware\exchange\SwitchCreatorMiddleware;
use common\modules\exchange\middleware\ExchangeDataMiddleware;
use common\models\user\Person;
use common\modules\economy\service\WalletMaintenance;
use common\modules\economy\service\WalletSchema;
use common\modules\economy\value\Money;
use common\services\user\CreditLedger;
use Exception;
use Yii;
use yii\base\InvalidConfigException;
use yii\di\NotInstantiableException;
use yii\web\IdentityInterface;

class ExchangeService
{
    /**
     * @param CreditExchange $model
     * @return void
     * @throws CountException
     * @throws InvalidConfigException
     * @throws NotInstantiableException
     * @throws \yii\db\Exception
     */
    public function create(CreditExchange $model): void
    {
        $model->scenario = CreditExchange::SCENARIO_CREATE;
        if (!$model->validate()) throw new \yii\web\UnprocessableEntityHttpException(implode(' ', $model->getFirstErrors()));
        Yii::$app->db->transaction(function () use ($model): void {
            WalletMaintenance::writable(Yii::$app->db);
            if (WalletSchema::ready(Yii::$app->db)) \common\modules\economy\service\LegacyCreditPolicy::exchange($model->credit);
            $container = App::container();
            $identity = App::user()->identity;

            // Serialize all creates for this user. The position limit and the
            // balance/credit update must be calculated from the same snapshot.
            $query = Person::find()->where(['user_id' => $identity->id]);
            $db = Yii::$app->db;
            if ($db->driverName === 'sqlite') {
                $db->createCommand('UPDATE ' . $db->quoteTableName(Person::tableName()) .
                    ' SET [[id]] = [[id]] WHERE [[user_id]] = :id', [':id' => $identity->id])->execute();
                $person = $query->one();
            } else {
                $command = $query->createCommand();
                $row = $db->createCommand($command->sql . ' FOR UPDATE', $command->params)->queryOne();
                $person = $row ? new Person() : null;
                if ($person !== null) Person::populateRecord($person, $row);
            }
            if ($person === null) {
                throw new Exception('Exchange owner not found.');
            }

            $middle = $container->get($this->getChecker($model));
            $middle
                ->linkWith($container->get(CheckCountMiddleware::class))
                ->linkWith($container->get(CreateMiddleware::class))
                ->linkWith($container->get(UpdatePersonMiddleware::class));

            $middle::$data = $container->get(ExchangeDataMiddleware::class, [$person, $model]);
            $middle::$data->setAvailableCount($this->availableCount($identity, $model, $person));

            if (!$middle->check()) {
                throw new Exception(
                    Yii::t('exchange', 'Error create')
                );
            }
        });
    }

    /**
     * @param CreditExchange $model
     * @return void
     * @throws InvalidConfigException
     * @throws \yii\db\Exception
     * @throws NotInstantiableException
     */
    public function confirm(CreditExchange $model): void
    {
        $this->positionTransaction($model, false, function (Person $person) use ($model): void {
            $container = App::container();
            $middle = $container->get(CheckFreeMiddleware::class);
            $middle
                ->linkWith($container->get($this->getChecker($model, false)))
                ->linkWith($container->get(PlayMiddleware::class))
                ->linkWith($container->get(SwitchCreatorMiddleware::class));
            $middle::$data = $container->get(ExchangeDataMiddleware::class, [$person, $model]);
            if (!$middle->check()) throw new Exception(Yii::t('exchange', 'Error confirm'));
        });
    }

    public function delete(CreditExchange $model): void
    {
        $this->positionTransaction($model, true, function (Person $person) use ($model): void {
            $container = App::container();
            $middle = $container->get(CheckFreeMiddleware::class);
            $middle
                ->linkWith($container->get(CheckMyMiddleware::class))
                ->linkWith($container->get(DeleteMiddleware::class));
            $middle::$data = $container->get(ExchangeDataMiddleware::class, [$person, $model]);
            if (!$middle->check()) throw new Exception(Yii::t('exchange', 'Error delete'));
        });
    }

    public function remove(): void
    {
        $db = Yii::$app->db; $user = (int)App::user()->id;
        $db->transaction(function () use ($db, $user): void {
            WalletMaintenance::writable($db);
            $ledger = new CreditLedger($db);
            // Lock the same rows that will be refunded. Concurrent confirmations cannot
            // make the sum and DELETE refer to different sets of positions.
            if ($db->driverName === 'sqlite') $ledger->lock('persone', ['user_id' => $user]);
            $query = (new \yii\db\Query())->from(CreditExchange::tableName())->where(['user_id' => $user])
                ->andWhere(['or', ['user_buyer' => null], ['<', 'user_buyer', 1]])->orderBy(['id' => SORT_ASC]);
            $command = $query->createCommand($db);
            $rows = $db->createCommand($command->sql . ($db->driverName === 'sqlite' ? '' : ' FOR UPDATE'), $command->params)->queryAll();
            if (!$rows) return;
            if (!$ledger->lock('persone', ['user_id' => $user])) throw new \RuntimeException('Account unavailable.');
            $exact = WalletSchema::ready($db); $credit = $exact ? Money::parse('0') : 0; $balance = 0; $ids = [];
            foreach ($rows as $row) {
                $type = trim($row['type']);
                if (!is_numeric($row['credit']) || !is_numeric($row['amount']) || !is_finite((float)$row['credit']) || !is_finite((float)$row['amount']) || $row['credit'] <= 0 || $row['amount'] <= 0) throw new \RuntimeException('Invalid exchange amounts.');
                if ($type === CreditExchange::EX_TYPE_BUY) {
                    $credit = $exact ? $credit->add(Money::fromLegacy((float)$row['credit'])) : $credit + $row['credit'];
                } elseif ($type === CreditExchange::EX_TYPE_SELL) $balance += $row['amount'];
                else throw new \RuntimeException('Unknown exchange type.');
                $ids[] = $row['id'];
            }
            if ($db->createCommand()->delete(CreditExchange::tableName(), ['id' => $ids, 'user_id' => $user])->execute() !== count($ids)) throw new \RuntimeException('Could not remove exchange positions.');
            $model = new CreditExchange();
            if ($exact) $ledger->changeExactWithBalance($user, $credit->decimal(), $balance, $model->getHistoryType(), 'Remove all');
            else {
                $data = new ExchangeDataMiddleware(Person::findOne(['user_id' => $user]), $model);
                $data->changingCredit = $credit; $data->changingBalance = $balance;
                $data->historyType = $model->getHistoryType(); $data->historyComment = 'Remove all';
                $middle = new UpdatePersonMiddleware(); $middle::$data = $data;
                if (!$middle->check()) throw new \RuntimeException('Could not refund exchange positions.');
            }
        });
    }

    /** Existing position -> sorted accounts; every state/history/commission write shares the transaction. */
    private function positionTransaction(CreditExchange $model, bool $ownerOnly, callable $work): void
    {
        $db = Yii::$app->db; $user = (int)App::user()->id;
        $db->transaction(function () use ($db, $user, $model, $ownerOnly, $work): void {
            WalletMaintenance::writable($db);
            $ledger = new CreditLedger($db);
            $row = $ledger->lock(CreditExchange::tableName(), ['id' => (int)$model->id]);
            if (!$row) throw new \yii\web\ConflictHttpException('Позиция уже удалена.');
            $owner = (int)$row['user_id'];
            if (($ownerOnly && $owner !== $user) || (!$ownerOnly && $owner === $user)) throw new \yii\web\ForbiddenHttpException('Эта операция недоступна.');
            if ((int)$row['user_buyer'] > 0) throw new \yii\web\ConflictHttpException('Позиция уже закрыта.');
            CreditExchange::populateRecord($model, $row);
            $ids = array_values(array_unique([$user, $owner])); sort($ids, SORT_NUMERIC);
            foreach ($ids as $id) if (!$ledger->lock('persone', ['user_id' => $id])) throw new \RuntimeException('Account unavailable.');
            $person = Person::findOne(['user_id' => $user]);
            if (!$person) throw new \RuntimeException('Account unavailable.');
            $work($person);
        });
    }

    /**
     * @param CreditExchange $model
     * @return float
     */
    public function pricePerThousand(CreditExchange $model): float
    {
        return 1000 * $model->amount / $model->credit;
    }

    /**
     * @param IdentityInterface $identity
     * @param CreditExchange $model
     * @return int
     */
    public function availableCount(IdentityInterface $identity, CreditExchange $model, ?Person $person = null): int
    {
        $count = $model::find()
            ->free()
            ->onlyBuy()
            ->my($identity)
            ->count();

        $rating = $person === null ? $identity->person->rating : $person->rating;
        $cnt = $rating / 10 - $count;
        return round(max($cnt, 0));
    }

    /**
     * @param CreditExchange $model
     * @param bool $create
     * @return AbstractMiddleware
     *
     * if confirm and type == sell, check credit
     * if create and type == sell, check balance
     */
    private function getChecker(CreditExchange $model, bool $create = true): string
    {
        if ($create) {
            return ($model->isBuy()) ? CheckCreditMiddleware::class : CheckBalanceMiddleware::class;
        }
        return ($model->isSell()) ? CheckCreditMiddleware::class : CheckBalanceMiddleware::class;
    }
}
