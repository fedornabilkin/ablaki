<?php

namespace common\middleware;


/**
 * Class AbstractMiddleware
 * @package common\middleware
 */
abstract class AbstractMiddleware
{
    /** @var DataMiddleware */
    public static $data;

    /** @var AbstractMiddleware */
    private $next;
    private static $errors = [];

    /**
     * Метод используется для построения цепочки объектов middleware.
     *
     * @param AbstractMiddleware $next
     * @return AbstractMiddleware
     */
    public function linkWith(AbstractMiddleware $next): AbstractMiddleware
    {
        $this->next = $next;

        return $next;
    }

    /**
     * @return bool
     * @throws \yii\db\Exception
     */
    public function check(): bool
    {
        $this->consoleLog(static::class);

        if (!$this->next) {
            self::$data->getTransaction()->commit();
            return true;
        }

        return $this->next->check();
    }

    /**
     * @return AbstractMiddleware
     */
    protected function getNext()
    {
        return $this->next;
    }

    /**
     * Вставляет мидлвэр между текущим и следующим
     * Если необходимо добавить сразу несколько middleware в середину цепочки, их необходимо линковать через этот метод
     *
     * $mdw = newUserExist();
     * $mdw->linkWith(new RoleCheck())->linkWith(new UserStory());
     *
     * Чтобы добавить new AuthKey() и new RoleCreate() между new RoleCheck() и new UserStory(), необходимо
     *
     * $mdwIns = new AuthKey();
     * $this->insertNext($mdwIns); // в контексте RoleCheck
     * $mdwIns->insertNext(new RoleCreate());
     *
     * @param bool $break
     * @param AbstractMiddleware $middleware
     */
    protected function insertNext(AbstractMiddleware $middleware, $break = false)
    {
        $next = $this->getNext();
        if ($next && !$break) {
            $middleware->linkWith($next);
        }
        $this->linkWith($middleware);
    }

    /**
     * @return bool
     */
    protected function stopProcessing($message)
    {
        self::$data->getTransaction()->rollBack();
        $this->setError($message);
        return false;
    }

    protected function setError($error)
    {
        self::$errors[] = $error;
        $this->consoleLog([
            'user' => self::$data->user->getAttributes(),
            'errors' => self::$errors,
        ]);
    }

    public function getErrors()
    {
        return self::$errors;
    }

    /**
     * @param string|array|object $text
     */
    protected function consoleLog($text)
    {
        \Yii::debug($text, 'Middleware');
    }
}
