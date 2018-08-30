<?php

namespace Akulov\SampleModule\Model\ResourceModel;

use Magento\Framework\Model\AbstractModel;

class Item extends  AbstractModel
{
    protected function _construct()
    {
        $this->_init(\Akulov\SampleModule\Model\ResourceModel\Item::class);
    }
}