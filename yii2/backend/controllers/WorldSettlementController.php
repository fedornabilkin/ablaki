<?php
namespace backend\controllers;

class WorldSettlementController extends \backend\components\WorldCrudController
{
    protected function nodeType(): string { return 'SETTLEMENT'; }
}
