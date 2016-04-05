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

    protected $_order;

    public static $definition = array(
        'table' => NLicConnector::TABLE_ORDER,
        'primary' => 'id_order',
        'multilang_shop' => true,
        'fields' => array(
            'id_order' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true),
            'id_shop' => array('type' => self::TYPE_INT, 'validate' => 'isNullOrUnsignedId'),
            'data' => array('type' => self::TYPE_STRING),
        ),
    );

    public function __construct($id, $id_lang = null, $id_shop = null)
    {
        parent::__construct($id, $id_lang, $id_shop);

        $this->data = unserialize($this->data);
        $this->_order = new Order($id);

        if (empty($this->_order->id)) {
            throw new PrestaShopException('Order not found');
        }

        $this->id_order = $this->_order->id;
    }

    public function getFields()
    {
        $this->data = serialize($this->data);
        $fields = parent::getFields();
        $this->data = unserialize($this->data);

        return $fields;
    }

    public function getOrder(){
        return $this->_order;
    }

    public function createLicenses(\NetLicensing\NetLicensingAPI $nlic_connect)
    {
        if (!empty($this->id)) return false;
        if (!$orderProducts = $this->_order->getProducts()) return false;

        $products = array();
        foreach ($orderProducts as $orderProduct) {
            $products[$orderProduct['id_product']] = $orderProduct;
        }

        NLicConnector::includeModel('NLicProduct');
        if (!$nlic_products = NLicProduct::getProducts(array_keys($products))) return false;

        try {
            $products_modules = \NetLicensing\ProductModuleService::connect($nlic_connect)->getList();
            $license_templates = \NetLicensing\LicenseTemplateService::connect($nlic_connect)->getList();

            foreach ($nlic_products as $nlic_product) {
                $product = $products[$nlic_product->id_product];
                $license_template = !empty($license_templates[$nlic_product->number]) ? $license_templates[$nlic_product->number] : new \NetLicensing\LicenseTemplate();
                $product_module = (!empty($license_template->getProductModuleNumber()) && $products_modules[$license_template->getProductModuleNumber()]) ? $products_modules[$license_template->getProductModuleNumber()] : new \NetLicensing\ProductModule();

                //create licensee
                $this->data[$nlic_product->id_product] = $this->_createLicense($nlic_connect, $product, $license_template, $product_module);
            }
        } catch (\NetLicensing\NetLicensingException $e) {
            Tools::dieOrLog(Tools::displayError(sprintf($e->getMessage() . ' Order ID: %d; Customer ID:%d; Shop ID:%d;', $this->_order->id, $this->_order->id_customer, $this->_order->id_shop)), true);
        }

        return true;
    }

    public function activateLicenses(\NetLicensing\NetLicensingAPI $nlic_connect)
    {
        if (empty($this->data)) return false;

        try {
            foreach ($this->data as &$data) {
                if (empty($data['error'])) {
                    //deactivate license
                    foreach ($data['licenses'] as &$license_data) {
                        /** @var  $license \NetLicensing\License */
                        $license = \NetLicensing\LicenseService::connect($nlic_connect)->get($license_data['number']);

                        if (!$license->getActive()) {
                            $license->setActive(true);
                            \NetLicensing\LicenseService::connect($nlic_connect)->update($license);
                            $license_data['active'] = $license->getActive();
                        }
                    }
                }
            }
        } catch (\NetLicensing\NetLicensingException $e) {
            Tools::dieOrLog(Tools::displayError(sprintf($e->getMessage() . ' Order ID: %d; Customer ID:%d; Shop ID:%d;', $this->_order->id, $this->_order->id_customer, $this->_order->id_shop)), true);
        }

        return true;
    }

    public function deactivateLicenses(\NetLicensing\NetLicensingAPI $nlic_connect)
    {

        if (empty($this->data)) return false;
       
        try {
            foreach ($this->data as &$data) {
                if (empty($data['error'])) {
                    //deactivate license
                    foreach ($data['licenses'] as &$license_data) {
                        /** @var  $license \NetLicensing\License */
                        $license = \NetLicensing\LicenseService::connect($nlic_connect)->get($license_data['number']);

                        if ($license->getActive()) {
                            $license->setActive(false);
                            \NetLicensing\LicenseService::connect($nlic_connect)->update($license);
                            $license_data['active'] = $license->getActive();
                        }
                    }
                }
            }
        } catch (\NetLicensing\NetLicensingException $e) {
            Tools::dieOrLog(Tools::displayError(sprintf($e->getMessage() . ' Order ID: %d; Customer ID:%d; Shop ID:%d;', $this->_order->id, $this->_order->id_customer, $this->_order->id_shop)), true);
        }

        return true;
    }

    public function checkLicensesState(\NetLicensing\NetLicensingAPI $nlic_connect)
    {
        if (empty($this->data)) return false;

        try {
            foreach ($this->data as &$data) {
                if (empty($data['error'])) {
                    //deactivate license
                    foreach ($data['licenses'] as &$license_data) {
                        /** @var  $license \NetLicensing\License */
                        $license = \NetLicensing\LicenseService::connect($nlic_connect)->get($license_data['number']);
                        $license_data['active'] = $license->getActive();
                    }
                }
            }
        } catch (\NetLicensing\NetLicensingException $e) {
            Tools::dieOrLog(Tools::displayError(sprintf($e->getMessage() . ' Order ID: %d; Customer ID:%d; Shop ID:%d;', $this->_order->id, $this->_order->id_customer, $this->_order->id_shop)), true);
        }

        return true;
    }

    protected function _createLicense(\NetLicensing\NetLicensingAPI $nlic_connect, $product, \NetLicensing\LicenseTemplate $license_template, \NetLicensing\ProductModule $product_module)
    {
        if (!$license_template->getNumber()) {
            $error = 'Unable to create the license, license template not found.';

            $license_data = array(
                'id_product' => $product['id_product'],
                'error' => $error
            );

            Tools::dieOrLog(Tools::displayError(sprintf($error . ' Order ID: %d; Customer ID:%d; Shop ID:%d; Product ID: %d', $this->_order->id, $this->_order->id_customer, $this->_order->id_shop, $product['id_product'])), false);
            return $license_data;
        }

        if ($license_template->getHidden()) {
            $error = 'Unable to create the license, license template not found.';

            $license_data = array(
                'id_product' => $product['id_product'],
                'error' => $error
            );

            Tools::dieOrLog(Tools::displayError(sprintf($error . ' License Template: %s; Order ID: %d; Customer ID:%d; Shop ID:%d; Product ID: %d', $license_template->getNumber(), $this->_order->id, $this->_order->id_customer, $this->_order->id_shop, $product['id_product'])), false);
            return $license_data;
        }

        if (!$product_module->getNumber()) {
            $error = 'Unable to create the license, product module not found.';

            $license_data = array(
                'id_product' => $product['id_product'],
                'error' => $error
            );
            Tools::dieOrLog(Tools::displayError(sprintf($error . ' License Template: %s; Order ID: %d; Customer ID:%d; Shop ID:%d; Product ID: %d', $license_template->getNumber(), $this->_order->id, $this->_order->id_customer, $this->_order->id_shop, $product['id_product'])), false);
            return $license_data;
        }

        if (!in_array($product_module->getLicensingModel(), NLicConnector::getAllowedLicenseModels())) {
            $error = sprintf('Unable to create the license, license template has not allowed licensing model %s, allowed licensing models: %s.', $product_module->getLicensingModel(), implode(',', NLicConnector::getAllowedLicenseModels()));

            $license_data = array(
                'id_product' => $product['id_product'],
                'error' => $error
            );

            Tools::dieOrLog(Tools::displayError(sprintf($error . ' License Template: %s; Order ID: %d; Customer ID:%d; Shop ID:%d; Product ID: %d', $license_template->getNumber(), $this->_order->id, $this->_order->id_customer, $this->_order->id_shop, $product['id_product'])), false);
            return $license_data;
        }

        //if no errors create licenses
        $customer = $this->_order->getCustomer();

        //create licensee
        $licensee = new \NetLicensing\Licensee();
        $licensee->setProductNumber($product_module->getProductNumber());
        $licensee->setProperty('name', !empty($customer->id) ? $customer->lastname . ' ' . $customer->firstname : 'ID Customer:' . $this->_order->id_customer);
        $licensee->setActive(true);

        \NetLicensing\LicenseeService::connect($nlic_connect)->create($licensee);

        $quantity = !empty($product['product_quantity']) ? $product['product_quantity'] : $product['minimal_quantity'];

        $product_name = Product::getProductName($product['id_product']);
        $product_name = ($product_name) ? $product_name : 'ID Product:' . $product['id_product'];

        $license_data = array(
            'id_product' => $product['id_product'],
            'licensing_type' => strtolower($license_template->getLicenseType()),
            'licensing_model' => strtolower($product_module->getLicensingModel())
        );

        for ($i = 1; $i <= $quantity; $i++) {

            $license = new \NetLicensing\License();
            $license->setActive(true);
            $license->setName($product_name);
            $license->setLicenseeNumber($licensee->getNumber());

            if ($license_template->getLicenseType() == 'TIMEVOLUME') $license->setProperty('startDate', 'now');

            $license->setLicenseTemplateNumber($license_template->getNumber());

            $tmp_licenses[] =\NetLicensing\LicenseService::connect($nlic_connect)->create($license);

            $license_data['licenses'][$license->getNumber()] = array(
                'active' => $license->getActive(),
                'number' => $license->getNumber()
            );
        }

        return $license_data;
    }
}