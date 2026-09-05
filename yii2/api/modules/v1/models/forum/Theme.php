<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 17.02.2023
 * Time: 21:12
 */

namespace api\modules\v1\models\forum;

use common\modules\forum\models\ForumTheme;

class Theme extends ForumTheme
{
    public function beforeValidate()
    {
        if ($this->isNewRecord) $this->view = 0;
        return parent::beforeValidate();
    }

    public function scenarios(): array
    {
        return array_merge(parent::scenarios(), ['create' => ['title'], 'update' => ['title']]);
    }

}
