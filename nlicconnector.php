<?php
/**
 * @author    Labs64 <netlicensing@labs64.com>
 * @license   Apache-2.0
 * @link      http://netlicensing.io
 * @copyright 2016 Labs64 NetLicensing
 */

if (!defined('_PS_VERSION_')) exit;

require_once __DIR__ . '/vendor/autoload.php';

class NLicConnector extends Module
{
    const NLIC_TABLE_PRODUCT = 'nlic_product';
    const NLIC_TABLE_CATEGORY = 'nlic_category';
    const NLIC_TABLE_ORDER = 'nlic_order';
    const VERSION = '1.0';

    protected $_prefix;
    protected $_module_path;

    public function __construct()
    {
        $this->name = 'nlicconnector';
        $this->tab = 'licensing';
        $this->version = self::VERSION;
        $this->author = 'labs64';

        $this->need_instance = 1;

        /*indicates which version of PrestaShop this module is compatible with*/
        $this->ps_versions_compliancy = array('min' => '1.5');

        $this->displayName = $this->l('NetLicensing Connector');
        $this->description = $this->l('NetLicensing Connector is the best way to monetize digital content.');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall?');

        //custom variables
        $this->_prefix = strtoupper($this->name);
        $this->_module_path = _PS_MODULE_DIR_ . 'nlicconnector';

        //Active bootstrap
        $this->bootstrap = true;

        /*display error on module page*/
        $username = Configuration::get($this->_prefix . '_USERNAME');
        $password = Configuration::get($this->_prefix . '_PASSWORD');

        if (empty($username) || empty($password)) $this->warning = $this->l('Authorization error. Check username and password.');

        parent::__construct();
    }

    public function install()
    {
        if (!parent::install()
            || !$this->registerHook('actionProductDelete')
            || !$this->registerHook('actionCategoryDelete')
            || !$this->registerHook('displayOrderConfirmation')
        ) return false;

        $this->_createTables();
        $this->_setDefaultConfiguration();

        return true;
    }

    public function uninstall()
    {
        $this->_deleteTables();
        $this->_deleteConfiguration();

        return parent::uninstall();
    }

    public function hookActionProductDelete($params)
    {
        if (!$this->active) return null;

        $product = !empty($params['product']) ? $params['product'] : null;

        if (!empty($product->id)) {
            $this->_includeModel('NLicProduct');
            $nlic_product = new NLicProduct($product->id);
            $nlic_product->delete();
        }
    }

    public function hookActionCategoryDelete($params)
    {
        if (!$this->active) return null;

        $category = !empty($params['category']) ? $params['category'] : null;
        if (!empty($category->id) && !$category->hasMultishopEntries()) {
            $this->_includeModel('NLicCategory');
            $nlic_category = new NLicCategory($category->id);
            $nlic_category->delete();
        }
    }

    public function hookDisplayOrderConfirmation($params)
    {
        if (!$this->active) return null;

        $errors = '';

        $objOrder = !empty($params['objOrder']) ? $params['objOrder'] : null;
        if (!$objOrder || !is_object($objOrder)) return null;

        if ($this->context->customer->id != $objOrder->id_customer) return null;

        //check if nlic order exist
        $this->_includeModel('NLicOrder');
        $nlic_order = new NLicOrder($objOrder->id);

        if ($nlic_order->id) {
            $this->context->smarty->assign('data', unserialize($nlic_order->data));
            return $this->display(__FILE__, 'views/templates/front/order_confirmation.tpl');
        } else {

            $this->_includeModel('NLicProduct');

            //check if order products it is nlic products
            $products = $objOrder->getProducts();
            $nlic_products = NLicProduct::getAllProducts();

            $products_is_nlic_products = array();
            foreach ($products as $product) {
                foreach ($nlic_products as $nlic_product) {
                    /** @var $nlic_product NLicProduct */
                    if ($product['id_product'] == $nlic_product->id_product) {
                        $products_is_nlic_products[$nlic_product->id_product] = array(
                            'nlic_product' => $nlic_product,
                            'product' => array(
                                'id_product' => $product['id_product'],
                                'name' => Product::getProductName($product['id_product']),
                                'image' => Link::getImageLink($product->link_rewrite, 10, 'small_default')
                            ),
                            'quantity' => !empty($product['quantity']) ? $product['quantity'] : $product['minimal_quantity']
                        );
                    }
                }
            }

            if ($products_is_nlic_products) {
                //get licensee
                try {

                    $nlic_connect = new \NetLicensing\NetLicensingAPI();
                    $nlic_connect->setUserName($this->_getUsername());
                    $nlic_connect->setPassword($this->_getPassword());

                    $product_modules = \NetLicensing\ProductModuleService::connect($nlic_connect)->getList();
                    $license_templates = \NetLicensing\LicenseTemplateService::connect($nlic_connect)->getList();

                    foreach ($products_is_nlic_products as $id_product => $data) {
                        /** @var  $nlic_product NLicProduct */
                        $nlic_product = $data['nlic_product'];
                        /** @var  $license_template \NetLicensing\LicenseTemplate */
                        $license_template = !empty($license_templates[$nlic_product->number]) ? $license_templates[$nlic_product->number] : null;

                        // if license template is null, delete connection and set error
                        if (!$license_template) {
                            $product = $data['product'];
                            $nlic_product->delete();
                            unset($products_is_nlic_products[$id_product]);
                            $errors .= $this->displayError(sprintf($this->l('Unable to create the license for product %s, contact the site administrator.'), $product->name));
                            continue;
                        }

                        /** @var  $product_module  \NetLicensing\ProductModule */
                        $product_module = $product_modules[$license_template->getProductModuleNumber()];

                        $nlic_product->product_number = $product_module->getProductNumber();
                        $nlic_product->product_module_number = $product_module->getNumber();
                    }

                    if (!$products_is_nlic_products) return $errors;

                    $licenses_data = array();

                    foreach ($products_is_nlic_products as $data) {
                        $nlic_product = $data['nlic_product'];
                        $product = $data['product'];

                        $licensee = new \NetLicensing\Licensee();
                        $licensee->setProductNumber($nlic_product->product_number);
                        $licensee->setActive(true);

                        \NetLicensing\LicenseeService::connect($nlic_connect)->create($licensee);

                        if (!$licensee->getNumber()) {
                            $errors .= $this->displayError(sprintf($this->l('Unable to create the license for product %s, contact the site administrator.'), $product['name']));
                            continue;
                        }

                        //create license
                        $licenses = array();

                        for ($i = 1; $i <= $data['quantity']; $i++) {

                            $license = new \NetLicensing\License();
                            $license->setActive(true);
                            $license->setName($product['name']);
                            $license->setLicenseeNumber($licensee->getNumber());
                            $license->setLicenseTemplateNumber($nlic_product->number);

                            \NetLicensing\LicenseService::connect($nlic_connect)->create($license);

                            if (!$license->getNumber()) {
                                $errors .= $this->displayError(sprintf($this->l('Unable to create the license for product %s, contact the site administrator.'), $product['name']));
                                break;
                            }

                            $licenses[] = $license->getNumber();
                        }

                        if ($licenses) {
                            $licenses_data[$product['id_product']] = array(
                                'product' => $product,
                                'licenses' => $licenses
                            );
                        }
                    }

                    if ($licenses_data) {
                        $nlic_order = new NLicOrder();
                        $nlic_order->id_order = $objOrder->id;
                        $nlic_order->data = serialize($licenses_data);
                        $nlic_order->save();

                        $this->context->smarty->assign('data', $licenses_data);
                        return $errors . $this->display(__FILE__, 'views/templates/front/order_confirmation.tpl');
                    }
                } catch (\NetLicensing\NetLicensingException $e) {
                    return $this->displayError($this->l('Unable to create the license, contact the site administrator.'));
                }
            }

        }
    }

    public function getContent()
    {
        if (!$this->active) return $this->displayError(sprintf($this->l('%s module  is disabled for this store.'), $this->displayName));
        return $this->_settingsForm() . $this->_settingsPage();
    }

    protected function _settingsPage()
    {
        return $this->display(__FILE__, 'views/templates/admin/settings/page.tpl');
    }

    protected function _settingsForm()
    {
        $errors = '';
        $messages = '';

        $submit = Tools::isSubmit('submit_' . $this->name);
        $step = Tools::getValue($this->name . '_step', 'save');

        if ($submit) {

            $username = ($step == 'save') ? Tools::getValue($this->name . '_username') : $this->_getUsername();
            $password = ($step == 'save') ? Tools::getValue($this->name . '_password') : $this->_getPassword();

            if (empty($username)) $errors .= $this->displayError($this->l('"Username" field is mandatory'));
            if (empty($password)) $errors .= $this->displayError($this->l('"Password" field is mandatory'));

            if (!$errors) {
                try {
                    //check authorization
                    $nlic_connect = new \NetLicensing\NetLicensingAPI();
                    $nlic_connect->setUserName($username);
                    $nlic_connect->setPassword($password);

                    $license_templates = \NetLicensing\LicenseTemplateService::connect($nlic_connect)->getList();

                    //save username and password if first step form submit
                    if ($step == 'save') {
                        //update settings
                        $this->_updateUsername(Tools::getValue($this->name . '_username'));
                        $this->_updatePassword(Tools::getValue($this->name . '_password'));
                        $messages .= $this->displayConfirmation($this->l('Configuration updated'));
                    }
                    //show second settings form if update values checked
                    if (Tools::getValue($this->name . '_update_products')) return $errors . $messages . $this->_secondStepSettingsForm($license_templates);

                    if ($step == 'update') {

                        $product_modules = \NetLicensing\ProductModuleService::connect($nlic_connect)->getList();

                        $import = Tools::getValue($this->name . '_import', null);
                        $update = Tools::getValue($this->name . '_update', null);
                        $delete = Tools::getValue($this->name . '_delete', null);

                        $sorted_products = $this->_sortProducts($license_templates);

                        //delete products
                        if ($delete) {
                            $deleted_products = 0;
                            foreach ($sorted_products['delete'] as $nlic_product) {
                                if ($nlic_product instanceof NLicProduct) {
                                    $product = new Product($nlic_product->id);
                                    $product_delete_state = $product->delete();
                                    if ($product_delete_state) $deleted_products++;
                                }
                            }
                            $messages .= $this->displayConfirmation(sprintf($this->l('%d product(s) deleted'), $deleted_products));
                        }

                        //update products
                        if ($update) {
                            $updated_products = 0;
                            foreach ($sorted_products['update'] as $nlic_product) {
                                if ($nlic_product instanceof NLicProduct) {
                                    $license_template = !empty($license_templates[$nlic_product->number]) ? $license_templates[$nlic_product->number] : null;

                                    if ($license_template instanceof \NetLicensing\LicenseTemplate) {
                                        $product = new Product($nlic_product->id_product);
                                        $product->name = array((int)Configuration::get('PS_LANG_DEFAULT') => $license_template->getName($license_template->getNumber()));

                                        //if need update price
                                        if ($update == 1) {
                                            $currencies = Tools::getValue($this->name . '_currency_rate');
                                            $currency = strtolower($license_template->getProperty('currency', 'EUR'));
                                            $exchange_rate = (!empty($currencies[$currency])) ? floatval($currencies[$currency]) : 0;
                                            if ($exchange_rate) $product->price = $license_template->getPrice(0) * $exchange_rate;
                                        }

                                        if ($product->save()) $updated_products++;
                                    }
                                }
                            }
                            $messages .= $this->displayConfirmation(sprintf($this->l('%d product(s) updated'), $updated_products));
                        }

                        //import products
                        if ($import) {
                            $imported_products = 0;

                            $this->_includeModel('NLicProduct');
                            $this->_includeModel('NLicCategory');

                            //get exist categories
                            $categories = NLicCategory::getCategoriesByNumbers(array_keys($product_modules), 'number');

                            //create products
                            /** @var $license_template \NetLicensing\LicenseTemplate */
                            foreach ($sorted_products['import'] as $license_template) {
                                /** @var $product_module \NetLicensing\ProductModule */
                                $product_module = $product_modules[$license_template->getProductModuleNumber()];
                                $product_module_number = $product_module->getNumber(null);

                                /** @var $nlic_category NLicCategory */
                                $nlic_category = !empty($categories[$product_module_number]) ? $categories[$product_module_number] : new NLicCategory();

                                //create category
                                if (!$nlic_category->id_category) {
                                    $category = new Category();
                                    $category->name = array((int)Configuration::get('PS_LANG_DEFAULT') => $product_module->getName($product_module->getNumber()));
                                    $category->id_parent = Configuration::get('PS_HOME_CATEGORY');
                                    $category->link_rewrite = array((int)Configuration::get('PS_LANG_DEFAULT') => $this->_createLinkRewrite($product_module->getName() . $product_module->getNumber()));
                                    $category->id_shop_default = $this->context->shop->id;

                                    //set category only for one shop
                                    $_POST['checkBoxShopAsso_category'] = array($this->context->shop->id => 1);
                                    $category->add();

                                    $nlic_category = new NLicCategory();
                                    $nlic_category->id_category = $category->id;
                                    $nlic_category->number = $product_module_number;
                                    $nlic_category->save();

                                    $categories[$product_module_number] = $nlic_category;
                                }

                                if ($nlic_category->id_category) {

                                    $product = new Product();
                                    $product->name = array((int)Configuration::get('PS_LANG_DEFAULT') => $license_template->getName($license_template->getNumber()));
                                    $product->link_rewrite = array((int)(Configuration::get('PS_LANG_DEFAULT')) => $this->_createLinkRewrite($license_template->getName() . $license_template->getNumber()));

                                    if ($import == 1) {
                                        $currencies = Tools::getValue($this->name . '_currency_rate');
                                        $currency = strtolower($license_template->getProperty('currency', 'EUR'));
                                        $exchange_rate = (!empty($currencies[$currency])) ? floatval($currencies[$currency]) : 0;
                                        if ($exchange_rate) $product->price = $license_template->getPrice(0) * $exchange_rate;
                                    }

                                    $product->id_category_default = $nlic_category->id_category;
                                    if ($product->add()) {
                                        $product->addToCategories(array($nlic_category->id_category));

                                        $nlic_product = new NLicProduct();
                                        $nlic_product->id_product = $product->id;
                                        $nlic_product->number = $license_template->getNumber();
                                        $nlic_product->save();

                                        $imported_products++;
                                    }
                                }
                            }
                            $messages .= $this->displayConfirmation(sprintf($this->l('%d product(s) imported'), $imported_products));
                        }
                    }
                } catch (\NetLicensing\NetLicensingException $e) {
                    if ($e->getCode() == '401') {
                        $errors .= $this->displayError($this->l('Authorization error. Check username and password.'));
                    }
                }
            }
        }

        return $errors . $messages . $this->_firstStepSettingsForm();
    }


    protected function _firstStepSettingsForm()
    {
        $description = array(
            sprintf(
                $this->l('%s for your free NetLicensing vendor account, then fill in the login information in the fields below'),
                $this->_createLink('https://go.netlicensing.io/app/v2/content/register.xhtml', 'Sign up', array('target' => '_blank'))
            ),
            sprintf(
                $this->l('Using NetLicensing %s, you can try out plugin functionality right away (username: demo / password: demo)'),
                $this->_createLink('https://go.netlicensing.io/app/v2/?lc=4b566c7e20&source=lmbox001', 'demo account', array('target' => '_blank'))
            )
        );

        $fields_form['form'] = array(
            'legend' => array(
                'title' => $this->l('NetLicensing Connect settings'),
                'icon' => 'icon-cogs'
            ),
            'description' => implode('</br>', $description),
            'input' => array(
                array(
                    'type' => 'hidden',
                    'name' => $this->name . '_step',
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('Username'),
                    'name' => $this->name . '_username',
                    'class' => 'fixed-width-lg',
                    'size' => 30,
                    'required' => true,
                    'hint' => $this->l('Enter your NetLicensing username.')
                ),
                array(
                    'type' => 'password',
                    'label' => $this->l('Password'),
                    'name' => $this->name . '_password',
                    'size' => 30,
                    'required' => true,
                    'hint' => $this->l('Enter your NetLicensing password.')
                ),
                array(
                    'type' => 'switch',
                    'label' => $this->l('Update products'),
                    'name' => $this->name . '_update_products',
                    'required' => false,
                    'is_bool' => true,
                    'hint' => $this->l('Synchronize products with netlicensing.'),
                    'values' => array(
                        array(
                            'id' => 'update_on',
                            'value' => 1,
                            'label' => $this->l('Enabled')
                        ),
                        array(
                            'id' => 'update_off',
                            'value' => 0,
                            'label' => $this->l('Disabled')
                        )
                    ),
                )
            ),
            'submit' => array(
                'title' => $this->l('Save'),
                'value' => 'submit_' . $this->name
            )
        );

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $lang = new Language((int)Configuration::get('PS_LANG_DEFAULT'));
        $helper->default_form_language = $lang->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submit_' . $this->name;
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = array(
            'fields_value' => array(
                $this->name . '_username' => Tools::getValue($this->name . '_username', $this->_getUsername()),
                $this->name . '_password' => Tools::getValue($this->name . '_password', $this->_getPassword()),
                $this->name . '_step' => 'save'

            ),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id
        );

        return $helper->generateForm(array($fields_form));
    }

    protected function _secondStepSettingsForm($license_templates)
    {
        //get all lt connections
        $sorted_products = $this->_sortProducts($license_templates);

        $delete_count = count($sorted_products['delete']);
        $update_count = count($sorted_products['update']);
        $import_count = count($sorted_products['import']);

        if ($delete_count || $update_count || $import_count) {

            //get currencies
            $currencies = array();

            foreach ($license_templates as $license_template) {
                /** @var  $license_template \NetLicensing\LicenseTemplate */
                $currency = $license_template->getProperty('currency', 'EUR');
                $currencies[$currency] = $currency;
            }

            $description = array(
                sprintf($this->l('Found %d product(s), for import'), $import_count),
                sprintf($this->l('Found %d product(s), for update'), $update_count),
                sprintf($this->l('Found %d disconnected product(s), for delete'), $delete_count),

            );

            $fields_form['form'] = array(
                'legend' => array(
                    'title' => $this->l('Update products'),
                    'icon' => 'icon-cogs'
                ),
                'description' => implode('</br>', $description),
                'input' => array(
                    array(
                        'type' => 'hidden',
                        'name' => $this->name . '_step',
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Update'),
                    'value' => 'submit_' . $this->name
                )
            );


            if ($import_count) {
                $fields_form['form']['input'][] = array(
                    'type' => 'radio',
                    'label' => $this->l('Import new product(s)'),
                    'name' => $this->name . '_import',
                    'required' => true,
                    'is_bool' => true,
                    'hint' => $this->l('Choose which action need to apply to new products.'),
                    'values' => array(
                        array(
                            'value' => 0,
                            'label' => $this->l('Skip')
                        ),
                        array(
                            'value' => 1,
                            'label' => $this->l('Import name and price'),
                        ),
                        array(
                            'value' => 2,
                            'label' => $this->l('Import only name')
                        )
                    ),
                );
            }

            if ($update_count) {
                $fields_form['form']['input'][] = array(
                    'type' => 'radio',
                    'label' => $this->l('Update exist product(s)'),
                    'name' => $this->name . '_update',
                    'required' => true,
                    'is_bool' => true,
                    'hint' => $this->l('Choose which action need to apply to exist products.'),
                    'values' => array(
                        array(
                            'value' => 0,
                            'label' => $this->l('Skip')
                        ),
                        array(
                            'value' => 1,
                            'label' => $this->l('Update name and price'),
                        ),
                        array(
                            'value' => 2,
                            'label' => $this->l('Update only name')
                        )
                    ),
                );
            }

            if ($delete_count) {
                $fields_form['form']['input'][] = array(
                    'type' => 'switch',
                    'label' => $this->l('Delete disconnected product(s)'),
                    'name' => $this->name . '_delete',
                    'required' => false,
                    'is_bool' => true,
                    'values' => array(
                        array(
                            'value' => 1,
                            'label' => $this->l('Enabled')
                        ),
                        array(
                            'value' => 0,
                            'label' => $this->l('Disabled')
                        )
                    ),
                );
            }

            $field_values[$this->name . '_step'] = 'update';

            //set default value
            if ($import_count) $field_values[$this->name . '_import'] = Tools::getValue($this->name . '_import', 1);
            if ($update_count) $field_values[$this->name . '_update'] = Tools::getValue($this->name . '_update', 0);
            if ($delete_count) $field_values[$this->name . '_delete'] = Tools::getValue($this->name . '_delete', 0);


            //set currency fields

            if ($currencies && ($import_count || $update_count)) {
                $currency_rates = Tools::getValue($this->name . '_currency_rate', array());

                foreach ($currencies as $currency) {
                    $field_name = $this->name . '_currency_rate[' . strtolower($currency) . ']';

                    $fields_form['form']['input'][] = array(
                        'type' => 'text',
                        'label' => sprintf($this->l('%s conversation rate'), $currency),
                        'name' => $field_name,
                        'class' => 'fixed-width-lg',
                        'size' => 30,
                        'hint' => $this->l('Exchange rates are calculated from one unit of your shop(s) default currency. For example, if the default currency is euros and your chosen currency is dollars, type "1.20" (1&euro; = $1.20).')
                    );

                    $field_values[$field_name] = !empty($currency_rates[strtolower($currency)]) ? $currency_rates[strtolower($currency)] : 1;
                }
            }

            $helper = new HelperForm();
            $helper->show_toolbar = false;
            $helper->table = $this->table;
            $lang = new Language((int)Configuration::get('PS_LANG_DEFAULT'));
            $helper->default_form_language = $lang->id;
            $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;

            $helper->identifier = $this->identifier;
            $helper->submit_action = 'submit_' . $this->name;
            $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
            $helper->token = Tools::getAdminTokenLite('AdminModules');
            $helper->tpl_vars = array(
                'fields_value' => $field_values,
                'languages' => $this->context->controller->getLanguages(),
                'id_language' => $this->context->language->id
            );

            return $helper->generateForm(array($fields_form));
        } else {
            return $this->displayError($this->l('No products to update')) . $this->_firstStepSettingsForm();
        }
    }

    protected function _sortProducts($license_templates)
    {
        $products = array('delete' => array(), 'import' => array(), 'update' => array());

        $this->_includeModel('NLicProduct');
        $db_products = NLicProduct::getAllProducts();

        //sort products
        $tmp_license_templates = $license_templates;
        $lt_numbers = array_keys($license_templates);

        //get product for delete and product for update
        foreach ($db_products as $product) {
            /** @var $product NLicProduct */
            if (!in_array($product->number, $lt_numbers)) {
                $products['delete'][$product->number] = $product;
                unset($tmp_license_templates[$product->number]);
            } else {
                $products['update'][$product->number] = $product;
                unset($tmp_license_templates[$product->number]);
            }
        }

        $products['import'] = $tmp_license_templates;

        return $products;
    }

    protected function _createLinkRewrite($text)
    {
        // replace non letter or digits by -
        $link_rewrite = preg_replace('~[^\pL\d]+~u', '-', $text);

        // transliterate
        $link_rewrite = iconv('utf-8', 'us-ascii//TRANSLIT', $link_rewrite);

        // remove unwanted characters
        $link_rewrite = preg_replace('~[^-\w]+~', '', $link_rewrite);

        // trim
        $link_rewrite = trim($link_rewrite, '-');

        // remove duplicate -
        $link_rewrite = preg_replace('~-+~', '-', $link_rewrite);

        // lowercase
        $link_rewrite = strtolower($link_rewrite);

        return $link_rewrite;
    }

    protected function _getUsername()
    {
        return Configuration::get($this->_prefix . '_USERNAME');
    }

    protected function _getPassword($decrypt = TRUE)
    {
        $password = Configuration::get($this->_prefix . '_PASSWORD');

        if ($decrypt && !empty($password)) {
            $encrypt_key = Configuration::getGlobalValue($this->_prefix . '_ENCRYPTION_KEY');

            $password = $this->_mc_decrypt($password, $encrypt_key);

        }

        return $password;
    }

    protected function _updateUsername($username)
    {
        Configuration::updateValue($this->_prefix . '_USERNAME', $username);
    }

    protected function _updatePassword($password)
    {
        $encrypt_key = Configuration::getGlobalValue($this->_prefix . '_ENCRYPTION_KEY');
        Configuration::updateValue($this->_prefix . '_PASSWORD', $this->_mc_encrypt($password, $encrypt_key));
    }

    protected function _getConfigFieldsValues()
    {
        $fields_values = array(
            $this->name . '_username' => Tools::getValue($this->name . '_username', $this->_getUsername()),
            $this->name . '_password' => Tools::getValue($this->name . '_password', $this->_getPassword())
        );

        return $fields_values;
    }

    protected function _createTables()
    {
        $sql = 'CREATE TABLE IF NOT EXISTS ' . _DB_PREFIX_ . self::NLIC_TABLE_PRODUCT . '(
                id_product INT(10) NOT NULL,
                id_shop INT(10) NOT NULL,
                number TEXT NOT NULL,
                PRIMARY KEY (id_product),
                INDEX shop_product (id_product, id_shop)
            ) ENGINE=INNODB CHARSET=utf8 COLLATE=utf8_general_ci;';

        if (!Db::getInstance()->Execute($sql)) return false;

        $sql = 'CREATE TABLE IF NOT EXISTS ' . _DB_PREFIX_ . self::NLIC_TABLE_CATEGORY . '(
                id_category INT(10) NOT NULL,
                number TEXT NOT NULL,
                PRIMARY KEY (id_category)
            ) ENGINE=INNODB CHARSET=utf8 COLLATE=utf8_general_ci;';

        if (!Db::getInstance()->Execute($sql)) return false;

        $sql = 'CREATE TABLE IF NOT EXISTS ' . _DB_PREFIX_ . self::NLIC_TABLE_ORDER . '(
                id_order INT(10) NOT NULL,
                id_shop INT(10) NOT NULL,
                data LONGBLOB,
                PRIMARY KEY (id_order),
                INDEX order_shop (id_order,id_shop)
            ) ENGINE=INNODB CHARSET=utf8 COLLATE=utf8_general_ci;';

        if (!Db::getInstance()->Execute($sql)) return false;
    }

    protected function _deleteTables()
    {
        Db::getInstance()->Execute("DROP TABLE " . _DB_PREFIX_ . self::NLIC_TABLE_PRODUCT . ";");
        Db::getInstance()->Execute("DROP TABLE " . _DB_PREFIX_ . self::NLIC_TABLE_CATEGORY . ";");
        Db::getInstance()->Execute("DROP TABLE " . _DB_PREFIX_ . self::NLIC_TABLE_ORDER . ";");
    }

    protected function _setDefaultConfiguration()
    {
        Configuration::updateGlobalValue($this->_prefix . '_ENCRYPTION_KEY', hash('sha256', uniqid()));
    }

    protected function _deleteConfiguration()
    {
        Configuration::deleteByName($this->_prefix . '_USERNAME');
        Configuration::deleteByName($this->_prefix . '_PASSWORD');
        Configuration::deleteByName($this->_prefix . '_ENCRYPTION_KEY');
    }

    protected function _createLink($path, $text = '', $attributes = array())
    {
        if ($path == 'AdminLink') $path = $this->context->link->getAdminLink('AdminModules', true) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;

        foreach ($attributes as $attribute => &$data) {
            $data = implode(' ', (array)$data);
            $data = $attribute . '="' . $data . '"';
        }
        $attributes = $attributes ? ' ' . implode(' ', $attributes) : '';

        return '<a href="' . $path . '" ' . $attributes . '>' . $this->l($text) . '</a>';
    }

    protected function _includeModel($name)
    {
        if (is_file($this->_module_path . '/classes/' . $name . '.php')) include_once $this->_module_path . '/classes/' . $name . '.php';
    }


    protected function _mc_encrypt($encrypt, $key)
    {
        $encrypt = serialize($encrypt);
        $iv = mcrypt_create_iv(mcrypt_get_iv_size(MCRYPT_RIJNDAEL_256, MCRYPT_MODE_CBC), MCRYPT_DEV_URANDOM);
        $key = pack('H*', $key);
        $mac = hash_hmac('sha256', $encrypt, substr(bin2hex($key), -32));
        $passcrypt = mcrypt_encrypt(MCRYPT_RIJNDAEL_256, $key, $encrypt . $mac, MCRYPT_MODE_CBC, $iv);
        $encoded = base64_encode($passcrypt) . '|' . base64_encode($iv);
        return $encoded;
    }


    protected function _mc_decrypt($decrypt, $key)
    {
        $decrypt = explode('|', $decrypt . '|');
        $decoded = base64_decode($decrypt[0]);
        $iv = base64_decode($decrypt[1]);
        if (strlen($iv) !== mcrypt_get_iv_size(MCRYPT_RIJNDAEL_256, MCRYPT_MODE_CBC)) {
            return false;
        }
        $key = pack('H*', $key);
        $decrypted = trim(mcrypt_decrypt(MCRYPT_RIJNDAEL_256, $key, $decoded, MCRYPT_MODE_CBC, $iv));
        $mac = substr($decrypted, -64);
        $decrypted = substr($decrypted, 0, -64);
        $calcmac = hash_hmac('sha256', $decrypted, substr(bin2hex($key), -32));
        if ($calcmac !== $mac) {
            return false;
        }
        $decrypted = unserialize($decrypted);
        return $decrypted;
    }
}