<?php

/**
 * @author    Labs64 <netlicensing@labs64.com>
 * @license   Apache-2.0
 * @link      http://netlicensing.io
 * @copyright 2016 Labs64 NetLicensing
 */
class NLicProduct extends ObjectModel
{
    public $id;
    public $id_product;
    public $id_shop;
    public $number;

    public static $definition = array(
        'table' => NLicConnector::TABLE_PRODUCT,
        'primary' => 'id_product',
        'multilang_shop' => true,
        'fields' => array(
            'id_product' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true),
            'id_shop' => array('type' => self::TYPE_INT, 'validate' => 'isNullOrUnsignedId'),
            'number' => array('type' => self::TYPE_STRING, 'required' => true),
        ),
    );

    public static function deleteMultiple($id_products = array(), $id_shop = null)
    {
        if ($id_products && is_array($id_products)) {
            if (!$id_shop) $id_shop = Context::getContext()->shop->id;
            return Db::getInstance()->delete(NLicConnector::TABLE_PRODUCT, "number IN('" . implode("','", $id_products) . "') AND id_shop=" . $id_shop);
        }

        return false;
    }

    public static function getProducts($ids_products = array(), $id_shop = null)
    {
        $products = array();

        if (!$id_shop) $id_shop = Context::getContext()->shop->id;
        $sql = "SELECT * FROM " . _DB_PREFIX_ . NLicConnector::TABLE_PRODUCT . " nlt WHERE nlt.id_shop=" . $id_shop;

        if ($ids_products) {
            if (is_array($ids_products)) {
                $sql .= " AND nlt.id_product IN ('" . implode("','", $ids_products) . "')";
            } else {
                $sql .= " AND nlt.id_product = '" . $ids_products . "'";
            }
        }

        if ($results = Db::getInstance()->ExecuteS($sql)) {
            foreach ($results as $row) {
                $product = new NLicProduct();
                $product->id = $row['id_product'];
                $product->id_product = $row['id_product'];
                $product->id_shop = $row['id_shop'];
                $product->number = $row['number'];
                $products[$row['id_product']] = $product;
            }
        }

        return $products;
    }
} 