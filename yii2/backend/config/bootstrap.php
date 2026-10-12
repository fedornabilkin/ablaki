<?php
Yii::$container->set('yii\grid\DataColumn', ['class' => \backend\widgets\EntityDataColumn::class]);
Yii::$container->set('yii\widgets\DetailView', ['class' => \backend\widgets\EntityDetailView::class]);
Yii::$container->set('yii\widgets\ActiveField', ['class' => \backend\widgets\EntityActiveField::class]);
