<?php
namespace backend\controllers;

class WorldRootController extends \backend\components\WorldCrudController
{
    protected function nodeType(): string { return 'WORLD'; }
}
