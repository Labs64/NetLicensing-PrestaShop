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
    public $name = 'nlicconnector';
    public $tab = 'licensing';
    public $version = '1.0';
    public $author = 'labs64';
    public $need_instance = 1;
    public $ps_versions_compliancy = array('min' => '1.5');

    const MODULE_PATH = _PS_MODULE_DIR_ . 'nlicconnector';

    const TABLE_PRODUCT = 'nlic_product';
    const TABLE_CATEGORY = 'nlic_category';
    const TABLE_ORDER = 'nlic_order';

    protected static $_allowed_license_models = array('Subscription');

    public function __construct()
    {
        $this->displayName = $this->l('NetLicensing Connector');
        $this->description = $this->l('NetLicensing Connector is the best way to monetize digital content.');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall?');

        //prefix for configuration 
        $this->_prefix = strtoupper($this->name);

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
            || !$this->registerHook('actionOrderStatusPostUpdate')
            || !$this->registerHook('displayAdminOrder')
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
            $this->includeModel('NLicProduct');
            $nlic_product = new NLicProduct($product->id);
            $nlic_product->delete();
        }
    }

    public function hookActionCategoryDelete($params)
    {
        if (!$this->active) return null;

        $category = !empty($params['category']) ? $params['category'] : null;
        if (!empty($category->id) && !$category->hasMultishopEntries()) {
            $this->includeModel('NLicCategory');
            $nlic_category = new NLicCategory($category->id);
            $nlic_category->delete();
        }
    }

    public function hookActionOrderStatusPostUpdate($params)
    {
        if (!$this->active) return null;
        $id_order = $params['id_order'];

        $this->includeModel('NLicOrder');
        $nlic_order = new NLicOrder($id_order);

        $status = $params['newOrderStatus'];

        $nlic_connect = new \NetLicensing\NetLicensingAPI();
        $nlic_connect->setUserName($this->_getUsername());
        $nlic_connect->setPassword($this->_getPassword());

        // if status not paid and nlic_order exist, deactivate license
        if (!$status->paid && $nlic_order->id) {
            if ($nlic_order->deactivateLicenses($nlic_connect)) {
                $nlic_order->save();
            }
        }

        //if status paid and nlic order exist activate license
        if ($status->paid && $nlic_order->id) {
            if ($nlic_order->activateLicenses($nlic_connect)) {
                $nlic_order->save();
            }
        }

        //if status paid and nlic_order not exist,create license
        if ($status->paid && !$nlic_order->id) {
            if ($nlic_order->createLicenses($nlic_connect)) {
                $nlic_order->save();
                //send email
                $this->_sendOrderLicensesEmail($nlic_order);
            }
        }
    }

    public function hookDisplayAdminOrder($params)
    {
        $messages = '';
        $errors = '';

        $this->includeModel('NLicOrder');
        $nlic_order = new NLicOrder($params['id_order']);

        if ($nlic_order->data) {

            $submit = Tools::isSubmit('submit_' . $this->name . '_order');

            if ($submit) {

                $nlic_connect = new \NetLicensing\NetLicensingAPI();
                $nlic_connect->setUserName($this->_getUsername());
                $nlic_connect->setPassword($this->_getPassword());

                if (Tools::getValue('submit_' . $this->name . '_order') == 'send_email') {
                    if ($nlic_order->checkLicensesState($nlic_connect)) {
                        $nlic_order->save();
                        //send email
                        if ($this->_sendOrderLicensesEmail($nlic_order)) {
                            $messages .= $this->displayConfirmation($this->l('Email send'));
                        } else {
                            $errors .= $this->displayError($this->l('Error sending mail'));
                        }
                    }
                }
                if (Tools::getValue('submit_' . $this->name . '_order') == 'check_state') {
                    if ($nlic_order->checkLicensesState($nlic_connect)) {
                        $nlic_order->save();
                        $messages .= $this->displayConfirmation($this->l('Licenses status updated'));
                    } else {
                        $errors .= $this->displayError($this->l('Failed to check licenses'));
                    }
                }
            }

            $licenses_count = 0;
            $licenses_data = array();

            foreach ($nlic_order->data as $data) {
                $name = Product::getProductName($data['id_product']);

                $image_url = '';
                $image_cover = ImageCore::getCover($data['id_product']);

                if ($image_cover) {
                    $image = new Image($image_cover['id_image']);
                    if ($image->getExistingImgPath()) {
                        $image_url = _PS_BASE_URL_ . _THEME_PROD_DIR_ . $image->getExistingImgPath() . "-cart_default.jpg";
                    }
                }

                $licenses_data[$data['id_product']] = array(
                    'name' => $name,
                    'image_url' => $image_url,
                );

                $licenses_data[$data['id_product']] += $data;

                if (empty($data['error']) && !empty($data['licenses'])) $licenses_count += count($data['licenses']);
            }

            $this->context->controller->addCSS($this->_path . 'views/css/order-form.css', 'all');
            $this->context->smarty->assign(array(
                    'mod' => $this->name,
                    'action' => $this->context->link->getAdminLink('AdminOrders', true) . '&id_order=' . $params['id_order'] . '&vieworder',
                    'data' => $licenses_data,
                    'count' => $licenses_count,
                    'send_email' => $this->_getSendEmail()
                )
            );

            return $errors . $messages . $this->display(__FILE__, 'views/templates/admin/order/form.tpl');
        }
    }

    public function hookDisplayOrderConfirmation($params)
    {
        $order = $params['objOrder'];

        //check if order paid
        $order_state = new OrderState($order->getCurrentState());

        if ($order_state->paid) {
            $this->includeModel('NLicOrder');
            $nlic_order = new NLicOrder($order->id);

            if ($nlic_order->id) {
                if (Tools::isSubmit('submit_' . $this->name . '_send_email')) {
                    $this->_sendOrderLicensesEmail($nlic_order);
                }

                $this->context->smarty->assign('mod', $this->name);
                return $this->display(__FILE__, 'views/templates/front/order/confirmation.tpl');
            }
        }
    }

    public function getContent()
    {
        $this->registerHook('displayAdminOrder');
        if (!$this->active) return $this->displayError(sprintf($this->l('%s module  is disabled for this store.'), $this->displayName));

        $errors = '';
        $messages = '';

        $submit = Tools::isSubmit('submit_' . $this->name);

        if ($submit) {
            $submit_values = Tools::getValue('submit_' . $this->name);

            switch ($submit_values) {
                case 'save':
                    $username = Tools::getValue($this->name . '_username');
                    $password = Tools::getValue($this->name . '_password');

                    if (empty($username)) {
                        $errors .= $this->displayError($this->l('"Username" field is mandatory'));
                    }
                    if (empty($password)) {
                        $errors .= $this->displayError($this->l('"Password" field is mandatory'));
                    }

                    if (!$errors) {
                        try {
                            //check authorization
                            $nlic_connect = new \NetLicensing\NetLicensingAPI();
                            $nlic_connect->setUserName($username);
                            $nlic_connect->setPassword($password);

                            $license_templates = \NetLicensing\LicenseTemplateService::connect($nlic_connect)->getList();

                            //update settings
                            $this->_updateUsername($username);
                            $this->_updatePassword($password);
                            $this->_updateSendEmail(Tools::getValue($this->name . '_send_email', 0));

                            $messages .= $this->displayConfirmation($this->l('Configuration updated'));

                        } catch (\NetLicensing\NetLicensingException $e) {
                            if ($e->getCode() == '401') {
                                $errors .= $this->displayError($this->l('Authorization error. Check username and password.'));
                            }
                        }
                    }
                    break;
                case 'update_form':
                    $form = $this->_getImportForm();
                    break;
            }
        }

        $submit_import = Tools::isSubmit('submit_import_' . $this->name);

        if ($submit_import) {

            $username = $this->_getUsername();
            $password = $this->_getPassword();

            try {
                //check authorization
                $nlic_connect = new \NetLicensing\NetLicensingAPI();
                $nlic_connect->setUserName($username);
                $nlic_connect->setPassword($password);

                $license_templates = \NetLicensing\LicenseTemplateService::connect($nlic_connect)->getList();
                $product_modules = \NetLicensing\ProductModuleService::connect($nlic_connect)->getList();

                $currency_eur_id = Currency::getIdByIsoCode('EUR');
                $currency = new Currency($currency_eur_id);

                $sorted_products = $this->_sortProducts($license_templates, $product_modules, $currency);

                //delete products
                if (!empty($sorted_products['delete']) && Tools::getValue($this->name . '_delete')) {
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
                if (!empty($sorted_products['update']) && Tools::getValue($this->name . '_update')) {
                    $updated_products = 0;

                    foreach ($sorted_products['update'] as $nlic_product) {
                        if ($nlic_product instanceof NLicProduct) {
                            $license_template = $license_templates[$nlic_product->number];

                            if ($license_template instanceof \NetLicensing\LicenseTemplate) {
                                $product = new Product($nlic_product->id_product);
                                $product->name = array((int)Configuration::get('PS_LANG_DEFAULT') => $license_template->getName($license_template->getNumber()));

                                //if need update price
                                if (Tools::getValue($this->name . '_update') == 2 && !empty($currency->id) && !empty($currency->active)) {
                                    $product->price = $license_template->getPrice(0) * $currency->conversion_rate;
                                }

                                if ($product->save()) $updated_products++;
                            }
                        }
                    }
                    $messages .= $this->displayConfirmation(sprintf($this->l('%d product(s) updated'), $updated_products));
                }


                //import products
                if (!empty($sorted_products['import']) && Tools::getValue($this->name . '_import')) {
                    $imported_products = 0;

                    $this->includeModel('NLicProduct');
                    $this->includeModel('NLicCategory');

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


                            if (Tools::getValue($this->name . '_import') == 2 && !empty($currency->id) && !empty($currency->active)) {
                                $product->price = $license_template->getPrice(0) * $currency->conversion_rate;
                            }

                            $product->id_category_default = $nlic_category->id_category;
                            if ($product->add()) {

                                $this->_createProductImage($product->id, '/views/img/' . strtolower($product_module->getLicensingModel()) . '-' . strtolower($license_template->getLicenseType()) . '.jpg');

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
            } catch (\NetLicensing\NetLicensingException $e) {
                $errors .= $this->displayError($this->l($e->getMessage()));
            }
        }

        if (empty($form)) {
            $form = $this->_getSettingsForm();
        }

        return $errors . $messages . $form . $this->_getSettingsPage();
    }

    protected function _sendOrderLicensesEmail(NLicOrder $nlic_order)
    {
        if (!$this->_getSendEmail() || empty($nlic_order->data)) return false;

        $mail_type = Configuration::get('PS_MAIL_TYPE');
        $template = 'order_licenses';

        //create mails from templates if mails don`t exist
        $mail_html_path = self::MODULE_PATH . '/mails/' . $this->context->language->iso_code . '/' . $template . '.html';
        $mail_txt_path = self::MODULE_PATH . '/mails/' . $this->context->language->iso_code . '/' . $template . '.txt';

        if (!file_exists($mail_html_path) || !file_exists($mail_txt_path)) {

            //create dir
            mkdir(self::MODULE_PATH . '/mails/' . $this->context->language->iso_code);

            $mail_html_common_template_path = self::MODULE_PATH . '/mails/templates/' . $template . '.html';
            $mail_txt_common_template_path = self::MODULE_PATH . '/mails/templates/' . $template . '.html';

            //create mail html template
            $mail_html = fopen($mail_html_path, 'w');
            $mail_html_content = file_get_contents($mail_html_common_template_path);
            fwrite($mail_html, $mail_html_content);
            fclose($mail_html);

            //create mail text template
            $mail_txt = fopen($mail_txt_path, 'w');
            $mail_txt_content = file_get_contents($mail_txt_common_template_path);
            fwrite($mail_txt, $mail_txt_content);
            fclose($mail_txt);
        }

        $licenses_data = array();

        foreach ($nlic_order->data as $data) {
            $name = Product::getProductName($data['id_product']);

            $image_url = '';
            $image_cover = ImageCore::getCover($data['id_product']);

            if ($image_cover) {
                $image = new Image($image_cover['id_image']);
                if ($image->getExistingImgPath()) {
                    $image_url = _PS_BASE_URL_ . _THEME_PROD_DIR_ . $image->getExistingImgPath() . "-cart_default.jpg";
                }
            }

            $licenses_data[$data['id_product']] = array(
                'name' => $name,
                'image_url' => $image_url,
            );
            $licenses_data[$data['id_product']] += $data;
        }

        if (!$licenses_data) return false;

        $this->context->smarty->assign('data', $licenses_data);

        if ($mail_type == Mail::TYPE_BOTH || $mail_type == Mail::TYPE_HTML) {
            $vars = array('{licenses}' => $this->display(__FILE__, 'views/templates/admin/order/email-html-table.tpl'));
        } else {
            $vars = array('{licenses}' => $this->display(__FILE__, 'views/templates/admin/order/email-txt-table.tpl'));
        }

        if (empty($vars)) return false;
        $customer = $nlic_order->getOrder()->getCustomer();
        return Mail::Send($this->context->language->id, 'order_licenses', Mail::l('Licenses', $this->context->language->id), $vars, $customer->email, null, null, null, null, null, dirname(__FILE__) . '/mails/', false);
    }

    protected function _getSettingsForm()
    {
        //add css
        $this->context->controller->addCSS($this->_path . 'views/css/settings-form.css', 'all');

        $this->context->smarty->assign(array(
            'mod' => $this->name,
            'username' => $this->_getUsername(),
            'password' => $this->_getPassword(),
            'send_email' => $this->_getSendEmail(),
            'action' => $this->context->link->getAdminLink('AdminModules', true) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name,
            'authorization' => ($this->_getUsername() && $this->_getPassword())
        ));

        return $this->display(__FILE__, 'views/templates/admin/settings/form.tpl');
    }

    protected function _getImportForm()
    {

        $errors = '';

        $username = $this->_getUsername();
        $password = $this->_getPassword();

        try {
            //check authorization
            $nlic_connect = new \NetLicensing\NetLicensingAPI();
            $nlic_connect->setUserName($username);
            $nlic_connect->setPassword($password);

            $license_templates = \NetLicensing\LicenseTemplateService::connect($nlic_connect)->getList();
            $product_modules = \NetLicensing\ProductModuleService::connect($nlic_connect)->getList();

            $currency_eur_id = Currency::getIdByIsoCode('EUR');
            $currency = new Currency($currency_eur_id);


            $sorted_products = $this->_sortProducts($license_templates, $product_modules, $currency);

            if (!$sorted_products) {
                return $this->displayError($this->l('No products to update')) . $this->_getSettingsForm();
            }

            $description = array();
            foreach ($sorted_products as $action => $products) {
                $count = count($products);
                $description[] = sprintf($this->l('Found %d product(s), for %s'), $count, $action);
            }

            if (empty($currency->id) || !$currency->active) {
                $description[] = $this->l('Can not import prices, the euro currency is not found or not active.');
            }

            $inputs = array();

            if (!empty($sorted_products['import'])) {
                $values = array(
                    array(
                        'value' => 0,
                        'label' => $this->l('Skip')
                    ),
                    array(
                        'value' => 1,
                        'label' => $this->l('Import only name')
                    )
                );

                if (!empty($currency->id) && !empty($currency->active)) {
                    $values[] = array(
                        'value' => 2,
                        'label' => $this->l('Import name and price'),
                    );
                }

                $inputs[] = array(
                    'type' => 'radio',
                    'label' => $this->l('Import new product(s)'),
                    'name' => $this->name . '_import',
                    'required' => true,
                    'is_bool' => true,
                    'hint' => $this->l('Choose which action need to apply to new products.'),
                    'values' => $values
                );
            }

            if (!empty($sorted_products['update'])) {
                $values = array(
                    array(
                        'value' => 0,
                        'label' => $this->l('Skip')
                    ),
                    array(
                        'value' => 1,
                        'label' => $this->l('Import only name')
                    )
                );

                if (!empty($currency->id) && !empty($currency->active)) {
                    $values[] = array(
                        'value' => 2,
                        'label' => $this->l('Import name and price'),
                    );
                }

                $inputs[] = array(
                    'type' => 'radio',
                    'label' => $this->l('Update exist product(s)'),
                    'name' => $this->name . '_update',
                    'required' => true,
                    'is_bool' => true,
                    'hint' => $this->l('Choose which action need to apply to exist products.'),
                    'values' => $values
                );
            }


            if (!empty($sorted_products['delete'])) {
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

            $fields_form['form'] = array(
                'legend' => array(
                    'title' => $this->l('Update products'),
                    'icon' => 'icon-download'
                ),
                'description' => implode('</br>', $description),
                'input' => $inputs,
                'submit' => array(
                    'title' => $this->l('Update'),
                    'icon' => 'process-icon-download',
                    'name' => 'submit_import_' . $this->name,
                )
            );

            //set default value
            $field_values = array();
            if (!empty($sorted_products['import'])) $field_values[$this->name . '_import'] = Tools::getValue($this->name . '_import', 1);
            if (!empty($sorted_products['update'])) $field_values[$this->name . '_update'] = Tools::getValue($this->name . '_update', 0);
            if (!empty($sorted_products['delete'])) $field_values[$this->name . '_delete'] = Tools::getValue($this->name . '_delete', 0);

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

        } catch (\NetLicensing\NetLicensingException $e) {
            $errors .= $this->displayError($this->l($e->getMessage()));
        }

        return implode('', $errors) . $this->_getSettingsForm();
    }


    protected function _getSettingsPage()
    {
        //add css
        $this->context->controller->addCSS($this->_path . 'views/css/settings-form.css', 'all');

        $this->context->smarty->assign('mod', $this->name);

        return $this->display(__FILE__, 'views/templates/admin/settings/page.tpl');
    }


    protected function _sortProducts($license_templates, $product_modules, $currency)
    {
        //sort products
        $sorted_products = array();

        //check if license template are allowed
        $allowed_license_templates = array();

        foreach ($license_templates as $license_template) {
            /** @var $license_template \NetLicensing\LicenseTemplate */
            if (!$license_template->getHidden()) {
                /** @var $product_module \NetLicensing\ProductModule */
                $product_module = $product_modules[$license_template->getProductModuleNumber()];

                if (in_array($product_module->getLicensingModel(), self::$_allowed_license_models)) {
                    $allowed_license_templates[$license_template->getNumber()] = $license_template;
                }
            }
        }

        if ($allowed_license_templates) {
            $this->includeModel('NLicProduct');
            $nlic_products = NLicProduct::getProducts();

            $tmp_license_templates = $allowed_license_templates;
            $allowed_lt_numbers = array_keys($allowed_license_templates);

            //get product for delete and product for update
            foreach ($nlic_products as $nlic_product) {
                if ($nlic_product instanceof NLicProduct) {
                    if (!in_array($nlic_product->number, $allowed_lt_numbers)) {
                        $sorted_products['delete'][$nlic_product->number] = $nlic_product;
                        unset($tmp_license_templates[$nlic_product->number]);
                    } else {
                        $product = new Product($nlic_product->id_product);
                        $license_template = $allowed_license_templates[$nlic_product->number];

                        $price = (!empty($currency->id) && !empty($currency->active)) ? $product->price / $currency->conversion_rate : $license_template->getPrice();

                        if ($product->name[(int)Configuration::get('PS_LANG_DEFAULT')] != $license_template->getName() || $price != $license_template->getPrice()) {
                            $sorted_products['update'][$nlic_product->number] = $nlic_product;
                        }

                        unset($tmp_license_templates[$nlic_product->number]);
                    }
                }
            }

            if ($tmp_license_templates) {
                $sorted_products['import'] = $tmp_license_templates;
            }
        }

        return $sorted_products;
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

    protected function _getSendEmail()
    {
        return Configuration::get($this->_prefix . '_SEND_EMAIL');
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

    protected function _updateSendEmail($state)
    {
        Configuration::updateValue($this->_prefix . '_SEND_EMAIL', $state);
    }

    protected function _createTables()
    {
        $sql = 'CREATE TABLE IF NOT EXISTS ' . _DB_PREFIX_ . self::TABLE_PRODUCT . '(
                id_product INT(10) NOT NULL,
                id_shop INT(10) NOT NULL,
                number TEXT NOT NULL,
                PRIMARY KEY (id_product),
                INDEX shop_product (id_product, id_shop)
            ) ENGINE=INNODB CHARSET=utf8 COLLATE=utf8_general_ci;';

        if (!Db::getInstance()->Execute($sql)) return false;

        $sql = 'CREATE TABLE IF NOT EXISTS ' . _DB_PREFIX_ . self::TABLE_CATEGORY . '(
                id_category INT(10) NOT NULL,
                number TEXT NOT NULL,
                PRIMARY KEY (id_category)
            ) ENGINE=INNODB CHARSET=utf8 COLLATE=utf8_general_ci;';

        if (!Db::getInstance()->Execute($sql)) return false;

        $sql = 'CREATE TABLE IF NOT EXISTS ' . _DB_PREFIX_ . self::TABLE_ORDER . '(
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
        Db::getInstance()->Execute("DROP TABLE " . _DB_PREFIX_ . self::TABLE_PRODUCT . ";");
        Db::getInstance()->Execute("DROP TABLE " . _DB_PREFIX_ . self::TABLE_CATEGORY . ";");
        Db::getInstance()->Execute("DROP TABLE " . _DB_PREFIX_ . self::TABLE_ORDER . ";");
    }

    protected function _setDefaultConfiguration()
    {
        Configuration::updateGlobalValue($this->_prefix . '_ENCRYPTION_KEY', hash('sha256', uniqid()));
    }

    protected function _deleteConfiguration()
    {
        Configuration::deleteByName($this->_prefix . '_USERNAME');
        Configuration::deleteByName($this->_prefix . '_PASSWORD');
        Configuration::deleteByName($this->_prefix . '_SEND_EMAIL');
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

    public static function getAllowedLicenseModels()
    {
        return self::$_allowed_license_models;
    }

    public static function includeModel($name)
    {
        if (is_file(self::MODULE_PATH . '/classes/' . $name . '.php')) require_once self::MODULE_PATH . '/classes/' . $name . '.php';
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

    protected function _toCamelCase($str, $capitaliseFirstChar = false)
    {
        if ($capitaliseFirstChar) {
            $str[0] = strtoupper($str[0]);
        }
        return preg_replace('/_([a-z])/e', "strtoupper('\\1')", $str);
    }

    protected function _fromCamelCase($str)
    {
        $str[0] = strtolower($str[0]);
        return preg_replace('/([A-Z])/e', "'_' . strtolower('\\1')", $str);
    }

    protected function _createProductImage($id_product, $path)
    {

        $image_path = self::MODULE_PATH . '/' . $path;

        if (is_file($image_path)) {
            $image_url = _PS_BASE_URL_ . '/' . $this->_path . $path;
            $shops = Shop::getShops(true, null, true);

            $image = new Image();
            $image->id_product = $id_product;
            $image->cover = true;

            if (($image->validateFields(false, true)) === true && ($image->validateFieldsLang(false, true)) === true && $image->save()) {
                $image->associateTo($shops);
                if (!self::copyImg($id_product, $image->id, $image_url, 'products', false)) {
                    $image->delete();
                }
            }
        }
    }

    protected static function copyImg($id_entity, $id_image = null, $url, $entity = 'products', $regenerate = true)
    {
        $tmpfile = tempnam(_PS_TMP_IMG_DIR_, 'ps_import');
        $watermark_types = explode(',', Configuration::get('WATERMARK_TYPES'));

        switch ($entity) {
            default:
            case 'products':
                $image_obj = new Image($id_image);
                $path = $image_obj->getPathForCreation();
                break;
            case 'categories':
                $path = _PS_CAT_IMG_DIR_ . (int)$id_entity;
                break;
            case 'manufacturers':
                $path = _PS_MANU_IMG_DIR_ . (int)$id_entity;
                break;
            case 'suppliers':
                $path = _PS_SUPP_IMG_DIR_ . (int)$id_entity;
                break;
        }

        $url = urldecode(trim($url));
        $parced_url = parse_url($url);

        if (isset($parced_url['path'])) {
            $uri = ltrim($parced_url['path'], '/');
            $parts = explode('/', $uri);
            foreach ($parts as &$part) {
                $part = rawurlencode($part);
            }
            unset($part);
            $parced_url['path'] = '/' . implode('/', $parts);
        }

        if (isset($parced_url['query'])) {
            $query_parts = array();
            parse_str($parced_url['query'], $query_parts);
            $parced_url['query'] = http_build_query($query_parts);
        }

        if (!function_exists('http_build_url')) {
            require_once(_PS_TOOL_DIR_ . 'http_build_url/http_build_url.php');
        }

        $url = http_build_url('', $parced_url);


        $orig_tmpfile = $tmpfile;

        if (Tools::copy($url, $tmpfile)) {

            // Evaluate the memory required to resize the image: if it's too much, you can't resize it.
            if (!ImageManager::checkImageMemoryLimit($tmpfile)) {
                @unlink($tmpfile);
                return false;
            }

            $tgt_width = $tgt_height = 0;
            $src_width = $src_height = 0;
            $error = 0;
            ImageManager::resize($tmpfile, $path . '.jpg', null, null, 'jpg', false, $error, $tgt_width, $tgt_height, 5,
                $src_width, $src_height);
            $images_types = ImageType::getImagesTypes($entity, true);

            if ($regenerate) {
                $previous_path = null;
                $path_infos = array();
                $path_infos[] = array($tgt_width, $tgt_height, $path . '.jpg');
                foreach ($images_types as $image_type) {
                    $tmpfile = self::get_best_path($image_type['width'], $image_type['height'], $path_infos);

                    if (ImageManager::resize($tmpfile, $path . '-' . stripslashes($image_type['name']) . '.jpg', $image_type['width'],
                        $image_type['height'], 'jpg', false, $error, $tgt_width, $tgt_height, 5,
                        $src_width, $src_height)
                    ) {
                        // the last image should not be added in the candidate list if it's bigger than the original image
                        if ($tgt_width <= $src_width && $tgt_height <= $src_height) {
                            $path_infos[] = array($tgt_width, $tgt_height, $path . '-' . stripslashes($image_type['name']) . '.jpg');
                        }
                        if ($entity == 'products') {
                            if (is_file(_PS_TMP_IMG_DIR_ . 'product_mini_' . (int)$id_entity . '.jpg')) {
                                unlink(_PS_TMP_IMG_DIR_ . 'product_mini_' . (int)$id_entity . '.jpg');
                            }
                            if (is_file(_PS_TMP_IMG_DIR_ . 'product_mini_' . (int)$id_entity . '_' . (int)Context::getContext()->shop->id . '.jpg')) {
                                unlink(_PS_TMP_IMG_DIR_ . 'product_mini_' . (int)$id_entity . '_' . (int)Context::getContext()->shop->id . '.jpg');
                            }
                        }
                    }
                    if (in_array($image_type['id_image_type'], $watermark_types)) {
                        Hook::exec('actionWatermark', array('id_image' => $id_image, 'id_product' => $id_entity));
                    }
                }
            }
        } else {
            @unlink($orig_tmpfile);
            return false;
        }
        unlink($orig_tmpfile);
        return true;
    }
}