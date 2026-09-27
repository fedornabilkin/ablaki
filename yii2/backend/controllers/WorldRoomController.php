<?php
namespace backend\controllers;

class WorldRoomController extends \backend\components\WorldCrudController
{
    protected function nodeType(): string { return 'ROOM'; }
}
