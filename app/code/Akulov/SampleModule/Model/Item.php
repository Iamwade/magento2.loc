<?php

namespace Akulov\SampleModule\Model;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Item extends  AbstractDb
{
    protected function _construct()
    {
        $this->_init('mastering_sample_item', 'id');
    }
} 