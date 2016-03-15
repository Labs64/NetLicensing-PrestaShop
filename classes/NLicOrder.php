<?php

/**
 * @author    Labs64 <netlicensing@labs64.com>
 * @license   Apache-2.0
 * @link      http://netlicensing.io
 * @copyright 2016 Labs64 NetLicensing
 */
class NLicOrder extends ObjectModel
{
    public $id;
    public $id_order;
    public $id_shop;
    public $data = array();

    public static $definition = array(
        'table' => NLicConnector::NLIC_TABLE_ORDER,
        'primary' => 'id_order',
        'multilang_shop' => true,
        'fields' => array(
            'id_order' => array('type' => self::TYPE_INT, 'validate' => 'isNullOrUnsignedId'),
            'id_shop' => array('type' => self::TYPE_INT, 'validate' => 'isNullOrUnsignedId'),
            'data' => array('type' => self::TYPE_STRING),
        ),
    );
} 