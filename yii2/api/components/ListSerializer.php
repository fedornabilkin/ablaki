<?php

namespace api\components;

use yii\rest\Serializer;

/** Keep old array responses; opt in to body pagination when CORS hides response headers. */
class ListSerializer extends Serializer
{
    protected function serializeDataProvider($dataProvider)
    {
        $this->collectionEnvelope = in_array($this->request->get('envelope'), ['1', 1], true) ? 'items' : null;
        return parent::serializeDataProvider($dataProvider);
    }
}
