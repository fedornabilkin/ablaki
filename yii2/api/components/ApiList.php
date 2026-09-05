<?php

namespace api\components;

use Yii;
use yii\data\ActiveDataProvider;
use yii\db\Expression;
use yii\db\Query;
use yii\web\BadRequestHttpException;

/** Shared, bounded server-side list contract. Authorization stays in the caller's query. */
class ApiList
{
    public static function provider($query, array $searchColumns = [], array $sortAttributes = ['id', 'created_at'], $searchCallback = null): ActiveDataProvider
    {
        foreach (['page', 'per-page'] as $parameter) {
            $value = Yii::$app->request->get($parameter);
            if ($value !== null && (!is_scalar($value) || !preg_match('/^[1-9][0-9]*$/D', (string)$value))) {
                throw new BadRequestHttpException('Invalid pagination parameter: ' . $parameter);
            }
        }
        $requestedSort = Yii::$app->request->get('sort');
        if ($requestedSort !== null && !is_string($requestedSort)) {
            throw new BadRequestHttpException('Invalid sort parameter.');
        }
        $q = self::queryText();
        if ($q !== '') {
            if ($searchCallback !== null) {
                $searchCallback($query, $q);
            } else {
                self::search($query, $searchColumns, $q);
            }
        }
        // Remove legacy fixed limits/order so count and URL sorting describe the complete query.
        $query->limit(null)->offset(null)->orderBy([]);
        foreach ($sortAttributes as $key => $attribute) {
            if (is_int($key) && is_string($attribute)) {
                unset($sortAttributes[$key]);
                $sortAttributes[$attribute] = [
                    'asc' => [$attribute => SORT_ASC],
                    'desc' => [$attribute => SORT_DESC],
                ];
                if ($attribute !== 'id') {
                    $sortAttributes[$attribute]['asc']['id'] = SORT_DESC;
                    $sortAttributes[$attribute]['desc']['id'] = SORT_DESC;
                }
            }
        }
        $sort = [
            'attributes' => $sortAttributes,
            'defaultOrder' => ['id' => SORT_DESC],
        ];
        return new ActiveDataProvider([
            'query' => $query,
            'pagination' => ['defaultPageSize' => 20, 'pageSizeLimit' => [1, 100]],
            'sort' => $sort,
        ]);
    }

    public static function queryText(): string
    {
        $q = Yii::$app->request->get('q', '');
        if (!is_string($q) || mb_strlen($q, 'UTF-8') > 100) {
            throw new BadRequestHttpException('Search must be a string of at most 100 characters.');
        }
        return trim($q);
    }

    public static function search($query, array $columns, string $q): void
    {
        if ($q !== '' && $columns !== []) {
            $query->andWhere(self::searchCondition($columns, $q));
        }
    }

    public static function searchCondition(array $columns, string $q): array
    {
        $condition = ['or'];
        foreach ($columns as $column) {
            // Column names are selected by server code, never copied from request parameters.
            $condition[] = ['like', new Expression('LOWER(' . Yii::$app->db->quoteColumnName($column) . ')'), mb_strtolower($q, 'UTF-8')];
        }
        return $condition;
    }

    public static function relatedUserCondition(array $foreignKeys, string $q): array
    {
        $users = (new Query())->select('id')->from('{{%user}}')
            ->where(self::searchCondition(['username'], $q));
        $condition = ['or'];
        foreach ($foreignKeys as $column) {
            $condition[] = ['in', $column, $users];
        }
        return $condition;
    }
}
