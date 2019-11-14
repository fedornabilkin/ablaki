<aside class="main-sidebar">

    <section class="sidebar">

        <!-- Sidebar user panel -->
        <div class="user-panel">
            <div class="pull-left image">
                <img src="<?= $directoryAsset ?>/img/user2-160x160.jpg" class="img-circle" alt="User Image"/>
            </div>
            <div class="pull-left info">
                <p><?=Yii::$app->user->identity->username?></p>

                <a href="#"><i class="fa fa-circle text-success"></i> Online</a>
            </div>
        </div>

        <!-- search form -->
<!--        <form action="#" method="get" class="sidebar-form">-->
<!--            <div class="input-group">-->
<!--                <input type="text" name="q" class="form-control" placeholder="Search..."/>-->
<!--              <span class="input-group-btn">-->
<!--                <button type='submit' name='search' id='search-btn' class="btn btn-flat"><i class="fa fa-search"></i>-->
<!--                </button>-->
<!--              </span>-->
<!--            </div>-->
<!--        </form>-->
        <!-- /.search form -->

        <?php
        $menuItems = [
            [
                'label' => 'Admin',
                'url' => '#',
                'items' => [
                    ['label' => 'Rbac', 'icon' => 'user-secret', 'url' => ['/admin/assignment']],
                    ['label' => 'Пользователи', 'icon' => 'user', 'url' => ['/user/admin']],
                    ['label' => 'Редиректы', 'icon' => 'share', 'url' => ['/redirect/manager']],
                    ['label' => 'Каталог', 'icon' => 'tree', 'url' => ['/binds/catalog']],
                ],
            ],


            ['label' => Yii::t('app', 'Customers'), 'icon' => 'users', 'url' => ['/customer/index']],
            ['label' => Yii::t('app', 'Vacancies'), 'icon' => 'user-md', 'url' => ['/vacancy/index']],
            ['label' => Yii::t('app', 'Candidates'), 'icon' => 'user-secret', 'url' => ['/candidate/index']],
            ['label' => Yii::t('app', 'Fact'), 'icon' => 'user-secret', 'url' => ['/fact/']],

            ['label' => 'Статьи', 'icon' => 'file-o', 'url' => ['/post']],

            ['label' => 'Other', 'options' => ['class' => 'header']],
            ['label' => 'Gii', 'icon' => 'file-code-o', 'url' => ['/gii']],
            ['label' => 'Debug', 'icon' => 'dashboard', 'url' => ['/debug']],

            ['label' => 'Login', 'url' => ['site/login'], 'visible' => Yii::$app->user->isGuest],

//            [
//                'label' => 'Some tools',
//                'icon' => 'share',
//                'url' => '#',
//                'items' => [
//                    ['label' => 'Gii', 'icon' => 'file-code-o', 'url' => ['/gii'],],
//                    ['label' => 'Debug', 'icon' => 'dashboard', 'url' => ['/debug'],],
//                    [
//                        'label' => 'Level One',
//                        'icon' => 'circle-o',
//                        'url' => '#',
//                        'items' => [
//                            ['label' => 'Level Two', 'icon' => 'circle-o', 'url' => '#',],
//                            [
//                                'label' => 'Level Two',
//                                'icon' => 'circle-o',
//                                'url' => '#',
//                                'items' => [
//                                    ['label' => 'Level Three', 'icon' => 'circle-o', 'url' => '#',],
//                                    ['label' => 'Level Three', 'icon' => 'circle-o', 'url' => '#',],
//                                ],
//                            ],
//                        ],
//                    ],
//                ],
//            ],

        ];

        $menuItems = \mdm\admin\components\Helper::filter($menuItems);
        ?>

        <!-- widget -->
        <?= dmstr\widgets\Menu::widget(
            [
                'options' => ['class' => 'sidebar-menu tree', 'data-widget'=> 'tree'],
                'items' => $menuItems,
            ]
        )?>

    </section>

</aside>
