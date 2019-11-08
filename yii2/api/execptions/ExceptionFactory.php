<?php

namespace api\components;

use yii\web\HttpException;

class ExceptionFactory
{
    const MODEL_NOT_FOUND = 1;

    /**
     * @var array Ключи массива:
     * 'status' - http-код, с которым будет отправлен ответ
     * 'message' - сообщение ошибки
     */
    protected static $errorsDetails = [
        self::MODEL_NOT_FOUND => ['status' => 400, 'message' => 'Магазин отсутствует'],
    ];


    /**
     * Создает класс исключения с нужным http-кодом и сообщением
     * @param int $code
     * @param \Exception|null $previous
     * @return HttpException
     */
    public static function get(int $code = 0, \Exception $previous = null)
    {
        if (empty(static::$errorsDetails[$code])) {
            return new HttpException(500, 'Неизвестная ошибка', 0, $previous);
        }

        $errorDetail = static::$errorsDetails[$code];
        if (!isset($errorDetail['status'], $errorDetail['message'])) {
            return new HttpException(500, 'Ошибка исключения', 0, $previous);
        }

        return new HttpException($errorDetail['status'], $errorDetail['message'], $code, $previous);
    }
}
