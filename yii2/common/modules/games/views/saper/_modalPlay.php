<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 27.05.2018
 * Time: 21:57
 *
 * @var $this yii\web\View
 */

use yii\helpers\Html;
use yii\helpers\Url;

?>

<div class="modal fade saper-play">
    <div class="modal-dialog modal-sm" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
                <h6 class="modal-title">Игра: <span class="gid"></span> ставка: <span class="kon"></span></h6>
            </div>
            <div class="modal-body">
                <div class="pole">
                    <table class="table table-bordered">
                        <tbody>
                        <?php for($r=1; $r<=5; $r++) : ?>
                            <tr>
                                <?php for($c=1; $c<=7; $c++) : ?>
                                    <td>
                                        <a class="play" href="" data-col="<?=$c?>" data-row="<?=$r?>" data-request="ajax" data-handler="SaperPlay" data-alert=".alert-play">
                                            <span class="fa fa-2x fa-apple"></span>
                                        </a>
                                    </td>
                                <?php endfor; ?>
                            </tr>
                        <?php endfor; ?>
                        </tbody>
                    </table>
                </div>
                <div class="m-t-1">
                    <a class="start btn btn-primary" href="<?=Url::to(['/games/saper/start'])?>" data-request="ajax" data-handler="SaperStart">Начать игру</a>
                    <?= Html::tag('span', '', ['class' => 'alert-play'])?>
                </div>
                <div class="double hidden-xs-up m-t-1">
                    <a class="double btn btn-sm btn-success" href="<?=Url::to(['/games/saper/double'])?>" data-request="ajax" data-handler="SaperDouble" data-alert=".alert-play">
                        Удвоить
                        <span class="kon"></span>
                    </a>
                    <div class="alert-double small hidden-xs-up">
                        Ставка: <span class="kon"></span> > <span class="kon-double"></span>
                        Прибыль: <span class="profit">90%</span> > <span class="profit-double">80%</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>