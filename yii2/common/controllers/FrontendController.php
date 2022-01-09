<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 13.05.2018
 * Time: 21:00
 */

namespace common\controllers;


use common\services\cookies\CookieService;
use common\singletones\Person;
use HttpRequestException;
use Yii;
use yii\web\Controller;
use yii\web\Response;

/**
 * @deprecated
 */
class FrontendController extends Controller
{
    public $cookieParams;
    protected $ajaxParams = [];

    protected $person;

    public function init()
    {
        parent::init();
        $this->cookieParams = Yii::$app->params['cookies'];

        new CookieService([
            'name' => $this->cookieParams['referrer']['name'],
            'value' => Yii::$app->request->referrer,
            'expire' => $this->cookieParams['referrer']['expire'],
        ]);
    }

    /**
     * @param array $params
     * @return array
     * @throws HttpRequestException
     */
    public function ajaxResponse($params = [])
    {
        if (Yii::$app->request->isAjax) {
            Yii::$app->response->format = Response::FORMAT_JSON;
            return array_merge($this->ajaxParams, $params);
        }

        throw new HttpRequestException();
    }

    /**
     * @param array $params
     * @return array
     */
    protected function getQueryParams($params = [])
    {
        return array_merge(Yii::$app->request->queryParams, $params);
    }

    /**
     * @return mixed
     * @deprecated
     */
    public function getUser()
    {
        $this->person = Person::getInstance();
        return $this->person->user;
    }
}
