<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 26.05.2018
 * Time: 14:49
 */

$nav[] = array('link'=>'/', 'anchor'=>'Ablaki.ru', 'title'=>'ablaki.ru - каждый день', 'icon'=>'fa-apple');
$nav[] = array('link'=>'/forum', 'anchor'=>'Форум', 'title'=>'Форум', 'icon'=>'fa-comments');
$nav[] = array('link'=>'/wiki', 'anchor'=>'Wiki', 'title'=>'Помощь', 'icon'=>'fa-question-circle');

if(!Yii::$app->user->isGuest){
    $nav[] = array('link'=>'/users/registration', 'title'=>'Регистрация пользователя', 'anchor'=>'Регистрация', 'icon'=>'fa-user-plus');
}


?>
<nav class="navbar-top navbar navbar-inverse">
    <div class="width-fix">
        <div class="nav navbar-nav">

            <?php
            foreach($nav as $item) :
                $active = $current = '';
//                if($item['link'] == '/'.Route::$controller){
//                    $active = 'active';
//                    $current = '<span class="sr-only">(current)</span>';
//                }

                ?>
                <a class="nav-item nav-link <?=$active?>" href="<?=$item['link']?>" title="<?=$item['title']?>">
                    <span class="fa <?=$item['icon']?>" aria-hidden="true"></span>
                    <span class="hidden-sm-down"><?=$item['anchor']?></span> <?=$current?>
                </a>
            <?php endforeach;?>
        </div>

        <ul class="loginbar nav navbar-nav pull-xs-right">
            <?php if(Yii::$app->user->isGuest) : ?>
                <li class="nav-item dropdown">
                    <a class="nav-link" data-toggle="dropdown" href="#" role="button" aria-haspopup="true" aria-expanded="false">
                        <span class="fa fa-fw fa-sign-in" aria-hidden="true"></span>
                        <span class="hidden-sm-down">Вход</span>
                    </a>
                    <div class="login dropdown-menu" role="menu">
                        <div class="dropdown-item">
                            login form
                            <?php // $view->renderView('_form_login') ?>
                        </div>
                    </div>
                </li>

            <?php else :?>
                <!--        <li class="bell nav-item dropdown">
                            <span class="nav-link dropdown-toggle" data-toggle="dropdown" href="#" role="button" aria-haspopup="true" aria-expanded="false">
                                <span class="fa fa-bell" aria-hidden="true"></span>
                                <span class=" hidden-xs-up notice-count label label-pill label-danger">0</span>
                            </span>
                            <ul class="dropdown-menu" role="menu">

                            <li>
                                <span class="fa fa-fw fa-exclamation-triangle text-warning" aria-hidden="true"></span>
                                <span class="text-muted">Сообщение</span>
                                <div class="notice">Нет новых уведомлений</div>
                            </li>
                            </ul>
                        </li>-->
                <li class="nav-item dropdown">
            <span class="nav-link dropdown-toggle" data-toggle="dropdown" href="#" role="button" aria-haspopup="true" aria-expanded="false">
                <img class="avatar img-rounded" src="/img/avatar/<?php //App::$user->avatar?>" alt="">
                <span class="login"><?= Yii::$app->user->username?></span>
            </span>
                    <div class="dropdown-menu" role="menu">

                        <a class="dropdown-item" href="/ablaki" title="Игра ablaki">
                            <span class="fa fa-fw fa-apple" aria-hidden="true"></span>
                            <span>Ablaki</span>
                        </a>
                        <a class="dropdown-item" href="/orel" title="Игра Орел-решка">
                            <span class="fa fa-fw fa-adjust" aria-hidden="true"></span>
                            <span>Орел-решка</span>
                        </a>
                        <a class="dropdown-item" href="/duel" title="Игра дуэль">
                            <span class="fa fa-fw fa-crosshairs" aria-hidden="true"></span>
                            <span>Дуэль</span>
                        </a>
                        <a class="dropdown-item" href="/fiveapple" title="Игра 5 яблок">
                            <span class="fa fa-fw fa-graduation-cap" aria-hidden="true"></span>
                            <span>5 яблок</span>
                        </a>

                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item" href="/balance/pay" title="Пополнить баланс">
                            <span class="fa fa-fw fa-plus text-success" aria-hidden="true"></span>
                            <span>Пополнить</span>
                        </a>
                        <a class="dropdown-item" href="/balance/zakaz" title="Заказать выплату">
                            <span class="fa fa-fw fa-usd" aria-hidden="true"></span>
                            <span>Заказать выплату</span>
                        </a>
                        <a class="dropdown-item" href="/exchange" title="Биржа кредитов">
                            <span class="fa fa-fw fa-refresh" aria-hidden="true"></span>
                            <span>Биржа кредитов</span>
                        </a>
                        <a class="dropdown-item" href="/transfer" title="Перевод кредитов">
                            <span class="fa fa-fw fa-exchange" aria-hidden="true"></span>
                            <span>Перевод кредитов</span>
                        </a>
                        <a class="dropdown-item" href="/balance" title="История баланса">
                            <span class="fa fa-fw fa-line-chart" aria-hidden="true"></span>
                            <span>История баланса</span>
                        </a>

                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item" href="/users/profile" title="Кабинет">
                            <span class="fa fa-fw fa-user" aria-hidden="true"></span>
                            <span>Кабинет</span>
                        </a>
                        <a class="dropdown-item" href="/statistic" title="Статистика">
                            <span class="fa fa-fw fa-bar-chart" aria-hidden="true"></span>
                            <span>Статистика</span>
                        </a>
                        <a class="dropdown-item referals" href="/users/referals" title="Рефералы">
                            <span class="fa fa-fw fa-users" aria-hidden="true"></span>
                            <span>Рефералы <span class="referals-nav tag tag-pill tag-success"></span></span>
                        </a>
                        <a class="dropdown-item" href="/users/logout" title="Выход">
                            <span class="fa fa-fw fa-sign-out" aria-hidden="true"></span>
                            <span>Выход</span>
                        </a>

                        <?php if(true) : ?>
                            <div class="dropdown-divider"></div>
                            <a class="dropdown-item bg-warning" href="/forum/lastcomments" title="Последние комментарии">
                                <span class="fa fa-fw fa-comments-o" aria-hidden="true"></span>
                                <span>Комментарии</span>
                            </a>
                            <a class="dropdown-item" href="/payments/zakaz" title="Выплаты">
                                <span class="fa fa-fw fa-usd text-danger" aria-hidden="true"></span>
                                <span>Выплаты</span>
                            </a>
                            <a class="dropdown-item" href="/payments" title="Настройки">
                                <span class="fa fa-fw fa-usd text-success" aria-hidden="true"></span>
                                <span>Платежи</span>
                            </a>
                            <a class="dropdown-item" href="/users" title="Пользователи">
                                <span class="fa fa-fw fa-users" aria-hidden="true"></span>
                                <span>Пользователи</span>
                            </a>
                            <a class="dropdown-item" href="/slider" title="Слайды">
                                <span class="fa fa-fw fa-sliders" aria-hidden="true"></span>
                                <span>Слайды</span>
                            </a>
                            <a class="dropdown-item" href="/todo" title="Задачи">
                                <span class="fa fa-fw fa-tasks" aria-hidden="true"></span>
                                <span>Задачи</span>
                            </a>
                            <a class="dropdown-item" href="/link" title="Битые ссылки">
                                <span class="fa fa-fw fa-external-link" aria-hidden="true"></span>
                                <span>Битые ссылки</span>
                            </a>
                            <a class="dropdown-item" href="/fact" title="Факты о сайте">
                                <span class="fa fa-fw fa-exclamation-circle" aria-hidden="true"></span>
                                <span>Факты о сайте</span>
                            </a>
                        <?php endif?>

                    </div>
                </li>
            <?php endif?>
        </ul>
    </div>
</nav>