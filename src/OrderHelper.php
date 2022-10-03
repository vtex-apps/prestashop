<?php

//use PrestaShop\PrestaShop\Core\Cart\CartRow;
//use PrestaShop\PrestaShop\Core\Domain\Cart\QueryResult\CartForOrderCreation;
//use PrestaShop\PrestaShop\Core\Domain\Cart\QueryResult\CartForOrderCreation\CartProduct;

class OrderHelper
{
    protected $sellerId;
    protected $context;
    protected $vtexPriceMultiplier = 100;

    public function __construct()
    {
        $this->sellerId = Configuration::get('seller_id');
        $this->context = Context::getContext();
    }

    public function create($orderInfo, $webServiceKey)
    {
        PrestaShopLogger::addLog("VTEX order data: ".json_encode($orderInfo), 1);
        $customer = $this->getCustomer($orderInfo);
        $address = $this->getAddress($customer, $orderInfo);
        $shop = (new Shop(Shop::getIdByName('vtex'))) ?: (new Shop(Configuration::get('PS_SHOP_DEFAULT')));
        $webService = new PrestaShopWebservice($this->context->shop->getBaseURL(true), $webServiceKey, false);

        $id_customer = $customer->id;
        $id_currency = Configuration::get('PS_CURRENCY_DEFAULT');
        $id_lang = Configuration::get('PS_LANG_DEFAULT');
        $id_address = $address->id;
        $shippingCost = array_reduce($orderInfo['shippingData']['logisticsInfo'], function(&$res, $item) {
            return $res + $item['price'];
        }, 0) / $this->vtexPriceMultiplier;
        $orderValue = $orderInfo['marketplacePaymentValue'] / $this->vtexPriceMultiplier;
//        $id_carrier = ($shippingCost > 0) ? 2 : 1;
        $id_carrier = Configuration::get('PS_CARRIER_DEFAULT');

        try {
            // Getting the empty XML document to send back completed
            $xml = $webService->get(array('url' => $this->context->shop->getBaseURL(true) .'api/carts?schema=blank'));

            // Adding dinamic and mandatory fields
            // Required
            $xml->cart->id_currency = $id_currency;
            $xml->cart->id_lang = $id_lang;
            $xml->cart->id_shop = $shop->id;

            foreach ($orderInfo['items'] as $k => $item) {
                $product = new Product($item['id']);
                $xml->cart->associations->cart_rows->cart_row[$k]->id = $k;
                $xml->cart->associations->cart_rows->cart_row[$k]->id_product = $item['id'];
                $xml->cart->associations->cart_rows->cart_row[$k]->id_product_attribute = $product->getDefaultIdProductAttribute();
                $xml->cart->associations->cart_rows->cart_row[$k]->id_address_delivery = $id_address;
                $xml->cart->associations->cart_rows->cart_row[$k]->quantity = $item['quantity'];
            }

            // Others
            $xml->cart->id_address_delivery = $id_address;
            $xml->cart->id_address_invoice  = $id_address;
            $xml->cart->id_customer = $id_customer;
            $xml->cart->carrier = $id_carrier;
            $xml->cart->date_add = date('Y-m-d H:i:s');
            $xml->cart->date_upd = date('Y-m-d H:i:s');

            // Adding the new customer's cart
            $opt = array('resource' => 'carts');
            $opt['postXml'] = $xml->asXML();

            try {
                $xml = $webService->add($opt);
            } catch (Exception $e) {
                PrestaShopLogger::addLog("Cart data: " . json_encode($xml), 4);
                throw new PrestaShopWebserviceException($e->getMessage(), $e->getCode());
            }

            $id_cart = $xml->cart->id;

            PrestaShopLogger::addLog("XML cart response data: " . json_encode($xml), 1);

            //---------------------------------------------------------------------------

            $order_module = 'ps_checkpayment';
            $xml = $webService->get(array('url' => $this->context->shop->getBaseURL(true) .'api/orders/?schema=blank'));
            // Adding dinamic and required fields
            // Required
            $xml->order->id_address_delivery = $id_address; // Customer address
            $xml->order->id_address_invoice = $id_address;
            $xml->order->id_cart = (int)$id_cart;
            $xml->order->id_currency = $id_currency;
            $xml->order->id_lang = $id_lang;
            $xml->order->id_customer = $id_customer;
            $xml->order->id_carrier = $id_carrier;
            $xml->order->module = $order_module;
            $xml->order->payment = 'Payment by check';
            $xml->order->total_paid = 0;
            $xml->order->total_paid_real = 0;
            $xml->order->total_products = $orderValue;
            $xml->order->total_products_wt = $orderValue;
            $xml->order->conversion_rate = 1;
            // Others
            $xml->order->valid = 1;
            $xml->order->current_state = 3;
            $xml->order->total_discounts = 0;
            $xml->order->total_shipping = $shippingCost;

            foreach ($orderInfo['items'] as $k => $item) {
                // Order Row. Required
                $product = new Product($item['id']);
                $price = $item['price'] / $this->vtexPriceMultiplier;
                $xml->order->associations->order_rows->order_row[$k]->id = $k;
                $xml->order->associations->order_rows->order_row[$k]->product_id = $item['id'];
                $xml->order->associations->order_rows->order_row[$k]->product_attribute_id = 0;
                $xml->order->associations->order_rows->order_row[$k]->product_quantity = $item['quantity'];
                $xml->order->associations->order_rows->order_row[$k]->reduction_percent = 0;
                // Order Row. Others
//                $xml->order->associations->order_rows->order_row[$k]->product_name = $product->name;
                $xml->order->associations->order_rows->order_row[$k]->product_reference = $product->reference;
                $xml->order->associations->order_rows->order_row[$k]->product_price = (float)$price;
                $xml->order->associations->order_rows->order_row[$k]->unit_price_tax_incl = (float)$price;
                $xml->order->associations->order_rows->order_row[$k]->unit_price_tax_excl = round($price / 1.19, 2);
            }

            // Creating the order
            $opt = array('resource' => 'orders');
            $opt['postXml'] = $xml->asXML();

            try {
                $xml = $webService->add($opt);
            } catch (Exception $e) {
                PrestaShopLogger::addLog("Order data: " . json_encode($xml), 4);
                throw new PrestaShopWebserviceException($e->getMessage(), $e->getCode());
            }

            $id_order = $xml->order->id;

            PrestaShopLogger::addLog("XML order response data: " . json_encode($xml), 1);

            try {
                //fix order prices, state, carrier, shipping
                $this->fixNewOrderValues($id_order, $orderInfo, $id_carrier);
            } catch (Exception $e) {
                PrestaShopLogger::addLog("Order shipping fix data: " . json_encode($xml), 4);
                throw new PrestaShopWebserviceException($e->getMessage(), $e->getCode());
            }

            if ($id_order) {
                $this->setVtexOrder($id_order, $orderInfo['marketplaceOrderId']);
                return $id_order;
            } else {
                PrestaShopLogger::addLog("Order not created data: " . json_encode($xml), 4);
            }

        } catch (Exception $e) {
            PrestaShopLogger::addLog($e->getMessage() . " " . $this->context->shop->getBaseURL(true), 4);
        }

        PrestaShopLogger::addLog("Order not created!", 4);
        return null;
    }

    public function getCustomer($orderInfo)
    {
        $customer = new Customer();

        if (!$customer->getByEmail($orderInfo['clientProfileData']['email'])) {
            $customer->email = $orderInfo['clientProfileData']['email'];
            $customer->lastname = $orderInfo['clientProfileData']['lastName'];
            $customer->firstname = $orderInfo['clientProfileData']['firstName'];
            $customer->passwd = $orderInfo['clientProfileData']['email'];
            $customer->add();
        }

        return $customer;
    }

    public function getAddress(Customer $customer, $orderInfo)
    {
        $returnAddress = null;
        $addresses = $customer->getAddresses(Configuration::get('PS_LANG_DEFAULT'));
        /** @var Address $address */
        foreach ($addresses as $address) {
            if ($address['id_country'] == self::getCountryIdByISO3($orderInfo['shippingData']['address']['country'])
                && $address['address1'] == $orderInfo['shippingData']['address']['street']
                . ', ' . $orderInfo['shippingData']['address']['number']
                . ', ' . $orderInfo['shippingData']['address']['complement']
                && $address['postcode'] == $orderInfo['shippingData']['address']['postalCode']
                && $address['city'] = $orderInfo['shippingData']['address']['city']
            ) {
                $returnAddress = new Address($address['id_address']);
                break;
            }
        }

        if (!$returnAddress) {
            $returnAddress = new Address();
            $returnAddress->id_customer = $customer->id;
            $returnAddress->id_country = self::getCountryIdByISO3($orderInfo['shippingData']['address']['country']);
            $returnAddress->firstname = $orderInfo['clientProfileData']['firstName'];
            $returnAddress->lastname = $orderInfo['clientProfileData']['lastName'];
            $returnAddress->address1 = $orderInfo['shippingData']['address']['street']
                . ', ' . $orderInfo['shippingData']['address']['number']
                . ', ' . $orderInfo['shippingData']['address']['complement'];
            $returnAddress->postcode = $orderInfo['shippingData']['address']['postalCode'];
            $returnAddress->city = $orderInfo['shippingData']['address']['city'];
            $returnAddress->phone_mobile = $orderInfo['clientProfileData']['phone'];
            $returnAddress->alias = "VTEX {$customer->id}";
            $returnAddress->add();
        }

        return $returnAddress;
    }

    public function getOrderId($marketplaceOrderId)
    {
        $result = Db::getInstance()->executeS('
        SELECT *
        FROM `' . _DB_PREFIX_ . 'vtex_orders`
        WHERE `vtex_order_id` = ' . $marketplaceOrderId);

        foreach ($result as $row) {
            return (int)$row['order_id'];
        }

        return 0;
    }

    public function setVtexOrder($orderId, $marketplaceOrderId)
    {
        $orderId = (int)$orderId;
        return Db::getInstance()->execute('INSERT INTO `' . _DB_PREFIX_ . 'vtex_orders` ' .
                '(`order_id`, `vtex_order_id`) VALUES ' .
                "({$orderId}, '{$marketplaceOrderId}')"
        );
    }

    public function getVtexOrderId($orderId)
    {
        $result = Db::getInstance()->executeS('
        SELECT *
        FROM `' . _DB_PREFIX_ . 'vtex_orders`
        WHERE `order_id` = ' . $orderId);

        foreach ($result as $row) {
            return $row['vtex_order_id'];
        }

        return 0;
    }

    public function fixNewOrderValues($orderId, $orderInfo, $idCarrier)
    {
//        $shippingCost = $orderInfo['shippingData']['logisticsInfo'][0]['price'] / $this->vtexPriceMultiplier;
        $shippingCost = array_reduce($orderInfo['shippingData']['logisticsInfo'], function(&$res, $item) {
                return $res + $item['price'];
            }, 0) / $this->vtexPriceMultiplier;
        $orderValue = $orderInfo['marketplacePaymentValue'] / $this->vtexPriceMultiplier;

        $order = new Order($orderId);

        foreach ($order->getProductsDetail() as $item) {
            $key = array_search($item['product_id'], array_column($orderInfo['items'], 'id'));
            if (isset($orderInfo['items'][$key])) {
                $originalPrice = $orderInfo['items'][$key]['price']  / $this->vtexPriceMultiplier;
                $detail = new OrderDetail($item['id_order_detail']);
                $detail->reduction_percent = 0;
                $detail->unit_price_tax_incl = $originalPrice;
                $detail->unit_price_tax_excl = round($originalPrice / 1.19, 2);
                $detail->total_price_tax_incl = $detail->unit_price_tax_incl * $detail->product_quantity;
                $detail->total_price_tax_excl = $detail->unit_price_tax_excl * $detail->product_quantity;
                $detail->update();
            }
        }

        if ($carrier = new OrderCarrier($order->getIdOrderCarrier())) {
            $carrier->id_carrier = $idCarrier;
            $carrier->shipping_cost_tax_incl = round($shippingCost, 2);
            $carrier->update();
        }

        foreach ($order->getOrderPayments() as $orderPayment) {
            $orderPayment->amount = round($orderValue, 2);
            $orderPayment->update();
        }

        foreach ($order->getHistory(Configuration::get('PS_LANG_DEFAULT')) as $orderHistory) {
            (new OrderHistory($orderHistory['id_order_history']))->delete();
        }

        $order->total_products = round(($orderValue - $shippingCost) / 1.19, 2);
        $order->total_products_wt = round($orderValue - $shippingCost, 2);
        $order->total_shipping = round($shippingCost, 2);
        $order->total_shipping_tax_incl = round($shippingCost, 2);
        $order->total_shipping_tax_excl = round($shippingCost / 1.19, 2);

        $order->total_paid = round($orderValue, 2);
        $order->total_paid_tax_incl = round($orderValue, 2);
        $order->total_paid_tax_excl = round($orderValue / 1.19, 2);
        $order->current_state = 11;

        $order->update();
    }

    public static function getCountryIdByISO3($iso3)
    {
        $countries = [
            'AFG' => 'AF',
            'ZAF' => 'ZA',
            'ALA' => 'AX',
            'ALB' => 'AL',
            'DZA' => 'DZ',
            'DEU' => 'DE',
            'AND' => 'AD',
            'AGO' => 'AO',
            'AIA' => 'AI',
            'ATA' => 'AQ',
            'ATG' => 'AG',
            'SAU' => 'SA',
            'ARG' => 'AR',
            'ARM' => 'AM',
            'ABW' => 'AW',
            'AUS' => 'AU',
            'AUT' => 'AT',
            'AZE' => 'AZ',
            'BHS' => 'BS',
            'BHR' => 'BH',
            'BGD' => 'BD',
            'BRB' => 'BB',
            'BLR' => 'BY',
            'BEL' => 'BE',
            'BLZ' => 'BZ',
            'BEN' => 'BJ',
            'BMU' => 'BM',
            'BTN' => 'BT',
            'BOL' => 'BO',
            'BES' => 'BQ',
            'BIH' => 'BA',
            'BWA' => 'BW',
            'BVT' => 'BV',
            'BRA' => 'BR',
            'BRN' => 'BN',
            'BGR' => 'BG',
            'BFA' => 'BF',
            'BDI' => 'BI',
            'CPV' => 'CV',
            'CYM' => 'KY',
            'KHM' => 'KH',
            'CMR' => 'CM',
            'CAN' => 'CA',
            'CHL' => 'CL',
            'CHN' => 'CN',
            'CXR' => 'CX',
            'CYP' => 'CY',
            'CCK' => 'CC',
            'COL' => 'CO',
            'COM' => 'KM',
            'COD' => 'CD',
            'COG' => 'CG',
            'COK' => 'CK',
            'KOR' => 'KR',
            'PRK' => 'KP',
            'CRI' => 'CR',
            'CIV' => 'CI',
            'HRV' => 'HR',
            'CUB' => 'CU',
            'CUW' => 'CW',
            'DNK' => 'DK',
            'DJI' => 'DJ',
            'DOM' => 'DO',
            'DMA' => 'DM',
            'EGY' => 'EG',
            'SLV' => 'SV',
            'ARE' => 'AE',
            'ECU' => 'EC',
            'ERI' => 'ER',
            'ESP' => 'ES',
            'EST' => 'EE',
            'USA' => 'US',
            'ETH' => 'ET',
            'FLK' => 'FK',
            'FRO' => 'FO',
            'FJI' => 'FJ',
            'FIN' => 'FI',
            'FRA' => 'FR',
            'GAB' => 'GA',
            'GMB' => 'GM',
            'GEO' => 'GE',
            'SGS' => 'GS',
            'GHA' => 'GH',
            'GIB' => 'GI',
            'GRC' => 'GR',
            'GRD' => 'GD',
            'GRL' => 'GL',
            'GLP' => 'GP',
            'GUM' => 'GU',
            'GTM' => 'GT',
            'GGY' => 'GG',
            'GIN' => 'GN',
            'GNQ' => 'GQ',
            'GNB' => 'GW',
            'GUY' => 'GY',
            'GUF' => 'GF',
            'HTI' => 'HT',
            'HMD' => 'HM',
            'HND' => 'HN',
            'HKG' => 'HK',
            'HUN' => 'HU',
            'IMN' => 'IM',
            'UMI' => 'UM',
            'IND' => 'IN',
            'IOT' => 'IO',
            'IDN' => 'ID',
            'IRN' => 'IR',
            'IRQ' => 'IQ',
            'IRL' => 'IE',
            'ISL' => 'IS',
            'ISR' => 'IL',
            'ITA' => 'IT',
            'JAM' => 'JM',
            'JPN' => 'JP',
            'JEY' => 'JE',
            'JOR' => 'JO',
            'KAZ' => 'KZ',
            'KEN' => 'KE',
            'KGZ' => 'KG',
            'KIR' => 'KI',
            'KWT' => 'KW',
            'LAO' => 'LA',
            'LSO' => 'LS',
            'LVA' => 'LV',
            'LBN' => 'LB',
            'LBR' => 'LR',
            'LBY' => 'LY',
            'LIE' => 'LI',
            'LTU' => 'LT',
            'LUX' => 'LU',
            'MAC' => 'MO',
            'MKD' => 'MK',
            'MDG' => 'MG',
            'MYS' => 'MY',
            'MWI' => 'MW',
            'MDV' => 'MV',
            'MLI' => 'ML',
            'MLT' => 'MT',
            'MNP' => 'MP',
            'MAR' => 'MA',
            'MHL' => 'MH',
            'MTQ' => 'MQ',
            'MUS' => 'MU',
            'MRT' => 'MR',
            'MYT' => 'YT',
            'MEX' => 'MX',
            'FSM' => 'FM',
            'MDA' => 'MD',
            'MCO' => 'MC',
            'MNG' => 'MN',
            'MNE' => 'ME',
            'MSR' => 'MS',
            'MOZ' => 'MZ',
            'MMR' => 'MM',
            'NAM' => 'NA',
            'NRU' => 'NR',
            'NPL' => 'NP',
            'NIC' => 'NI',
            'NER' => 'NE',
            'NGA' => 'NG',
            'NIU' => 'NU',
            'NFK' => 'NF',
            'NOR' => 'NO',
            'NCL' => 'NC',
            'NZL' => 'NZ',
            'OMN' => 'OM',
            'UGA' => 'UG',
            'UZB' => 'UZ',
            'PAK' => 'PK',
            'PLW' => 'PW',
            'PSE' => 'PS',
            'PAN' => 'PA',
            'PNG' => 'PG',
            'PRY' => 'PY',
            'NLD' => 'NL',
            'PER' => 'PE',
            'PHL' => 'PH',
            'PCN' => 'PN',
            'POL' => 'PL',
            'PYF' => 'PF',
            'PRI' => 'PR',
            'PRT' => 'PT',
            'QAT' => 'QA',
            'SYR' => 'SY',
            'CAF' => 'CF',
            'REU' => 'RE',
            'ROU' => 'RO',
            'GBR' => 'GB',
            'RUS' => 'RU',
            'RWA' => 'RW',
            'ESH' => 'EH',
            'BLM' => 'BL',
            'KNA' => 'KN',
            'SMR' => 'SM',
            'MAF' => 'MF',
            'SXM' => 'SX',
            'SPM' => 'PM',
            'VAT' => 'VA',
            'VCT' => 'VC',
            'SHN' => 'SH',
            'LCA' => 'LC',
            'SLB' => 'SB',
            'WSM' => 'WS',
            'ASM' => 'AS',
            'STP' => 'ST',
            'SEN' => 'SN',
            'SRB' => 'RS',
            'SYC' => 'SC',
            'SLE' => 'SL',
            'SGP' => 'SG',
            'SVK' => 'SK',
            'SVN' => 'SI',
            'SOM' => 'SO',
            'SDN' => 'SD',
            'SSD' => 'SS',
            'LKA' => 'LK',
            'SWE' => 'SE',
            'CHE' => 'CH',
            'SUR' => 'SR',
            'SJM' => 'SJ',
            'SWZ' => 'SZ',
            'TJK' => 'TJ',
            'TWN' => 'TW',
            'TZA' => 'TZ',
            'TCD' => 'TD',
            'CZE' => 'CZ',
            'ATF' => 'TF',
            'THA' => 'TH',
            'TLS' => 'TL',
            'TGO' => 'TG',
            'TKL' => 'TK',
            'TON' => 'TO',
            'TTO' => 'TT',
            'TUN' => 'TN',
            'TKM' => 'TM',
            'TCA' => 'TC',
            'TUR' => 'TR',
            'TUV' => 'TV',
            'UKR' => 'UA',
            'URY' => 'UY',
            'VUT' => 'VU',
            'VEN' => 'VE',
            'VGB' => 'VG',
            'VIR' => 'VI',
            'VNM' => 'VN',
            'WLF' => 'WF',
            'YEM' => 'YE',
            'ZMB' => 'ZM',
            'ZWE' => 'ZW'
        ];

        return Country::getByIso($countries[$iso3]);
    }
}
