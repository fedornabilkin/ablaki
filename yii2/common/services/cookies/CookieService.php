<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 13.05.2018
 * Time: 21:03
 */

namespace common\services\cookies;


use Yii;
use yii\base\Component;

/**
 * Class CookieService
 * @package common\services\cookies
 */
class CookieService extends Component
{
    public $name;
    public $value;
    public $expire = 60 * 60 * 24;

    public function __construct(array $config = [])
    {
        parent::__construct($config);

        $cookies = Yii::$app->request->cookies;
        $newCookie = $this->create();
        if (!$cookies->has($this->name) && $newCookie){
            Yii::$app->response->cookies->add($newCookie);
        }else{
            $this->value = $cookies->getValue($this->name);
        }
    }

    public function getValue()
    {
        return $this->value;
    }

    /**
     * Создает куку, если есть имя и значение
     * @return null|\yii\web\Cookie
     */
    public function create()
    {
        if(!$this->name or !$this->value){
            return null;
        }
        $cookie = new \yii\web\Cookie([
            'name' => $this->name,
            'value' => $this->value,
            'expire' => time() + $this->expire,
        ]);

        return $cookie;
    }
}