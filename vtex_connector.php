<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

include_once(_PS_MODULE_DIR_ . 'vtex_connector/classes/WebserviceSpecificManagementFulfillment.php');
include_once(_PS_MODULE_DIR_ . 'vtex_connector/src/Vtex.php');
include_once(_PS_MODULE_DIR_ . 'vtex_connector/src/ApiVtex.php');
include_once(_PS_MODULE_DIR_ . 'vtex_connector/src/PSWebServiceLibrary.php');
include_once(_PS_MODULE_DIR_ . 'vtex_connector/src/ProductHelper.php');
include_once(_PS_MODULE_DIR_ . 'vtex_connector/src/OrderHelper.php');

class vtex_connector extends Module
{
    protected $vClient;
    protected $apiVClient;

    public function __construct()
    {
        $this->name = 'vtex_connector';
        $this->version = '0.0.1';
        $this->author = 'CustomSoft';
        $this->need_instance = 1;
        $this->ps_versions_compliancy = array('min' => '1.7.1.0', 'max' => _PS_VERSION_);
        $this->bootstrap = true;

        $this->vClient = new Vtex();
        $this->apiVClient = new ApiVtex();

        parent::__construct();

        $this->displayName = $this->trans('VTEX Connector', array(), 'Admin.Global');
        $this->description = $this->trans('A module that allows VTEX marketplaces to sell products from an external prestashop seller', array(), 'Admin.Global');

        $this->confirmUninstall = $this->trans('Are you sure you want to uninstall?', array(), 'Admin.Global');
    }

    public function install()
    {
        if (Shop::isFeatureActive())
            Shop::setContext(Shop::CONTEXT_ALL);

        return parent::install()
            && $this->registerHook('addWebserviceResources')
            && $this->registerHook('actionProductUpdate')
            && $this->registerHook('actionOrderStatusPostUpdate')
            && $this->registerHook('actionAdminOrdersTrackingNumberUpdate')
            && $this->registerHook('actionSetInvoice')
            && $this->installDb();
    }

    public function uninstall()
    {
        if (!parent::uninstall() && !$this->uninstallDB())
            return false;
        return true;
    }

    public function installDb()
    {
        return Db::getInstance()->execute('
		CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'vtex_orders` (
			`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
			`order_id` INT(11) UNSIGNED NOT NULL,
			`vtex_order_id` VARCHAR(50) NOT NULL,
			INDEX (`vtex_order_id`)
		) ENGINE = '._MYSQL_ENGINE_.' CHARACTER SET utf8 COLLATE utf8_general_ci;');
    }

    protected function uninstallDb()
    {
        Db::getInstance()->execute('DROP TABLE `'._DB_PREFIX_.'vtex_orders`');
        return true;
    }

    public function hookActionAdminOrdersTrackingNumberUpdate($params)
    {
        /** @var Order $order */
        $order = $params['order'];
        /** @var Carrier $carrier */
        $carrier = $params['carrier'];
        if ($orderCarrier = new OrderCarrier($order->getIdOrderCarrier())) {
            $amount = $orderCarrier->shipping_cost_tax_incl;
            $orderCarrier->shipping_cost_tax_incl = $amount;
            $order->update();
        }

        if (($vtexOrderId = (new OrderHelper())->getVtexOrderId($order->id))
            && $order->hasInvoice()
            && ($trackingNumber = $order->getWsShippingNumber())
        ) {
            $this->vClient->sendTracking(
                $vtexOrderId,
                $order->invoice_number,
                ['number' => $trackingNumber, 'carrier' => $carrier->name]);
        }
    }

    public function hookAddWebserviceResources($params)
    {
        return [
            'fulfillment' => array(
                'specific_management' => true
            ),
        ];
    }

    public function hookActionOrderStatusPostUpdate($params)
    {
        if ($vtexOrderId = (new OrderHelper())->getVtexOrderId($params['id_order'])) {
            if (in_array($params['newOrderStatus']->id, [Configuration::get('PS_OS_CANCELED')])) {
                $this->vClient->changeOrderState('cancel', $vtexOrderId);
            }
        }
    }

    public function hookActionProductUpdate($params)
    {
        $product = $params['product'];

        try {
            $this->vClient->changeNotification($product->id);
        } catch (\Exception $e) {
            $this->apiVClient->sendSKUSuggestion($product);
        }
    }

    public function hookActionSetInvoice($params)
    {
        /** @var Order $order */
        $order = $params['Order'];
        if ($vtexOrderId = (new OrderHelper())->getVtexOrderId($order->id)) {
            if ($order->hasInvoice()) {
                $this->vClient->invoice($vtexOrderId, $order->getInvoicesCollection());
            }
        }
    }

    public function displayForm()
    {
        // < init fields for form array >
        $fields_form[0]['form'] = array(
            'legend' => array(
                'title' => $this->l('VTEX Connnector Module'),
            ),
            'input' => array(
                array(
                    'type' => 'text',
                    'label' => $this->l('Marketplace account'),
                    'name' => 'vendor_name',
                    'size' => 20,
                    'required' => true
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('App key'),
                    'name' => 'app_key',
                    'size' => 20,
                    'required' => true
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('App token'),
                    'name' => 'app_token',
                    'size' => 20,
                    'required' => true
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('Seller ID'),
                    'name' => 'seller_id',
                    'size' => 20,
                    'required' => true
                ),
            ),
            'submit' => array(
                'title' => $this->l('Save'),
                'class' => 'btn btn-default pull-right'
            )
        );

        // < load helperForm >
        $helper = new HelperForm();

        // < module, token and currentIndex >
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex.'&configure='.$this->name;

        // < title and toolbar >
        $helper->title = $this->displayName;
        $helper->show_toolbar = true;        // false -> remove toolbar
        $helper->toolbar_scroll = true;      // yes - > Toolbar is always visible on the top of the screen.
        $helper->submit_action = 'submit'.$this->name;
        $helper->toolbar_btn = array(
            'save' =>
                array(
                    'desc' => $this->l('Save'),
                    'href' => AdminController::$currentIndex.'&configure='.$this->name.'&save'.$this->name.
                        '&token='.Tools::getAdminTokenLite('AdminModules'),
                ),
            'back' => array(
                'href' => AdminController::$currentIndex.'&token='.Tools::getAdminTokenLite('AdminModules'),
                'desc' => $this->l('Back to list')
            )
        );

        // < load current value >
        $helper->fields_value['vendor_name'] = Tools::getValue('vendor_name', Configuration::get('vendor_name'));
        $helper->fields_value['app_key'] = Tools::getValue('app_key', Configuration::get('app_key'));
        $helper->fields_value['app_token'] = Tools::getValue('app_token', Configuration::get('app_token'));
        $helper->fields_value['seller_id'] = Tools::getValue('seller_id', Configuration::get('seller_id'));

        return $helper->generateForm($fields_form);
    }

    public function getContent()
    {
        $output = null;


        // < here we check if the form is submited for this module >
        if (Tools::isSubmit('submit'.$this->name)) {
            $vendor_name = strval(Tools::getValue('vendor_name'));
            $app_key = strval(Tools::getValue('app_key'));
            $app_token = strval(Tools::getValue('app_token'));
            $seller_id = strval(Tools::getValue('seller_id'));

            // < make some validation, check if we have something in the input >
            if (!isset($vendor_name) || !isset($app_key) || !isset($app_token) || !isset($seller_id))
                $output .= $this->displayError($this->l('Please complete all fields.'));
            else
            {
                // < this will update the value of the Configuration variable >
                Configuration::updateValue('vendor_name', $vendor_name);
                Configuration::updateValue('app_key', $app_key);
                Configuration::updateValue('app_token', $app_token);
                Configuration::updateValue('seller_id', $seller_id);


                // < this will display the confirmation message >
                $output .= $this->displayConfirmation($this->l('Configuration updated!'));
            }
        }
        return $output.$this->displayForm();
    }
}
