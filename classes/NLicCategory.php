<?php

/**
 * @author    Labs64 <netlicensing@labs64.com>
 * @license   Apache-2.0
 * @link      http://netlicensing.io
 * @copyright 2016 Labs64 NetLicensing
 */
class NLicCategory extends ObjectModel
{
    public $id;
    public $id_category;
    public $number;

    public static $definition = array(
        'table' => NLicConnector::NLIC_TABLE_CATEGORY,
        'primary' => 'id_category',
        'multilang_shop' => false,
        'fields' => array(
            'id_category' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true),
            'number' => array('type' => self::TYPE_STRING, 'required' => true),
        ),
    );

    public static function getCategoriesByNumbers($numbers = array(), $order_by = 'id_category')
    {
        $categories = array();

        if ($numbers) {
            $sql = "SELECT * FROM " . _DB_PREFIX_ . NLicConnector::NLIC_TABLE_CATEGORY . " WHERE number IN('" . implode("','", $numbers) . "')";

            if ($results = Db::getInstance()->ExecuteS($sql)) {
                foreach ($results as $row) {
                    $order_by = !in_array($order_by, array('id_category', 'number')) ? 'id_category' : $order_by;
                    $category = new NLicCategory();
                    $category->id = $row['id_category'];
                    $category->id_category = $row['id_category'];
                    $category->number = $row['number'];

                    $categories[$row[$order_by]] = $category;
                }
            }
        }

        return $categories;
    }
} 