<?php

use GuzzleHttp\Client;

class Vtex
{
    protected $api_endpoint = "https://{{vendor}}.myvtex.com/";

    protected $vendorName;
    protected $appKey;
    protected $appToken;
    protected $sellerId;

    protected $client;

    protected $vtexPriceMultiplier = 100;

    protected $context;

    public function __construct()
    {
        $this->vendorName = Configuration::get('vendor_name');
        $this->appKey = Configuration::get('app_key');
        $this->appToken = Configuration::get('app_token');
        $this->sellerId = Configuration::get('seller_id');
        $this->context = Context::getContext();

        $this->client = new Client([
            'base_url' => str_replace('{{vendor}}', $this->vendorName, $this->api_endpoint),
            'defaults' => [
                'headers' => [
                    'Content-Type' => 'application/json; charset=utf-8',
                    'Accept' => 'application/json',
                    'X-VTEX-API-AppKey' => $this->appKey,
                    'X-VTEX-API-AppToken' => $this->appToken,
                ]
            ]
        ]);
        $this->client->setDefaultOption('verify', false);
    }

    public function changeNotification($skuId)
    {
        try {
            $this->client->post("api/catalog_system/pvt/skuseller/changenotification/{$this->sellerId}/{$skuId}");
            return true;
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
    }

    public function changeOrderState($newState ,$vtexOrderId)
    {
        if ($newState == 'cancel') {
            try {
                $this->client->post("api/oms/pvt/orders/{$vtexOrderId}/{$newState}");
            } catch (\Exception $e) {}
        } else {
            try {
                $this->client->post("api/oms/pvt/orders/{$vtexOrderId}/changestate/{$newState}");
            } catch (\Exception $e) {}
        }
    }

    public function invoice($vtexOrderId, $invoices)
    {
        foreach ($invoices as $invoice) {

            $body = $this->getInvoiceData($invoice);

            try {
                $this->client->post("api/oms/pvt/orders/{$vtexOrderId}/invoice", [
                    'json' => $body
                ]);
                return true;
            } catch (\Exception $e) {
                return false;
            }
        }
    }

    public function sendTracking($vtexOrderId, $invoiceNumber, $track)
    {
        try {
            $this->client->patch("api/oms/pvt/orders/{$vtexOrderId}/invoice/{$invoiceNumber}", [
                'json' => [
                    'trackingNumber' => $track['number'],
                    'courier' => $track['carrier']
                ]
            ]);
            return true;
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
    }

    public function createCategory($data)
    {
        try {
            $this->client->post("api/catalog/pvt/category", [
                'json' => [
                    "Id" => $data['entity_id'],
                    "Name" => $data['name'],
                    "Keywords" => '',
                    "Title" => $data['name'],
                    "Description" => '',
                    "AdWordsRemarketingCode" => '',
                    "LomadeeCampaignCode" => '',
                    "FatherCategoryId" => $data['parent_id'],
                    "GlobalCategoryId" => '',
                    "ShowInStoreFront" => true,
                    "IsActive" => true,
                    "ActiveStoreFrontLink" => true,
                    "ShowBrandFilter" => true,
                    "Score" => '',
                    "StockKeepingUnitSelectionMode" => 'SPECIFICATION'
                ]
            ]);
            return null;
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    public function updateCategory($data)
    {
        try {
            $this->client->put("api/catalog/pvt/category/{$data['entity_id']}", [
                'json' => [
                    "Name" => $data['name'],
                    "Keywords" => '',
                    "Title" => $data['name'],
                    "Description" => '',
                    "AdWordsRemarketingCode" => '',
                    "LomadeeCampaignCode" => '',
                    "FatherCategoryId" => $data['parent_id'],
                    "GlobalCategoryId" => '',
                    "ShowInStoreFront" => true,
                    "IsActive" => true,
                    "ActiveStoreFrontLink" => true,
                    "ShowBrandFilter" => true,
                    "Score" => '',
                    "StockKeepingUnitSelectionMode" => 'SPECIFICATION'
                ]
            ]);
            return null;
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    public function createBrand($data)
    {
        try {
            $this->client->post("api/catalog/pvt/brand", [
                'json' => [
                    "Name" => $data['label'],
                    "Keywords" => '',
                    "Text" => $data['label'],
                    "SiteTitle" => '',
                    "AdWordsRemarketingCode" => '',
                    "LomadeeCampaignCode" => '',
                    "Score" => '',
                    "MenuHome" => true,
                    "Active" => true,
                    "LinkID" => ''
                ]
            ]);
            return null;
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    public function getBrands()
    {
        try {
            $response = $this->client->get("api/catalog_system/pvt/brand/list");
            return json_decode($response->getBody()->getContents(), true);
        } catch (\Exception $e) {
            return [];
        }
    }

    public function getBrand($data)
    {
        try {
            $brands = $this->getBrands();
            $found = array_filter($brands, function ($var) use ($data) {
                return ($var['name'] == $data['label']);
            });

            if (count($found)) {
                return true;
            }

            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getCategoryById($id)
    {
        try {
            $response = $this->client->get("api/catalog/pvt/category/{$id}");
            return json_decode($response->getBody()->getContents(), true);
        } catch (\Exception $exception) {
            return false;
        }
    }

//    public function sendSKUSpecifications(Product $product)
//    {
//        if (isset($product->getCategoryIds()[0]) && ($categoryId = $product->getCategoryIds()[0])) {
//            if ($this->getCategoryById($categoryId)) {
//                $specs = $this->productHelper->getSpecifications($product, true);
//                foreach ($specs as $spec) {
//                    if ($specification = $this->createSpecification($categoryId, $spec['FieldName'], $spec['Type'])) {
//                        foreach ($spec['FieldValues'] as $fieldValue) {
//                            try {
//                                $this->createSpecificationValue($specification['Id'], $fieldValue);
//                            } catch (\Exception $exception) {
//                            }
//                        }
//                    }
//                }
//            }
//        }
//    }

//    public function createSpecification($categoryId, $name, $fieldType = 'Combo', $group = 'Specificatii', $type = true)
//    {
//        try {
//            $response = $this->client->post("api/catalog/pvt/specification", [
//                'json' => [
//                    "FieldTypeId" => self::getSpecificationFieldTypeId($fieldType),
//                    "CategoryId" => $categoryId,
//                    "FieldGroupId" => $this->getSpecificationGroupId($categoryId, $group),
//                    "Name" => $name,
//                    "Description" => $name,
//                    "Position" => 1,
//                    "IsFilter" => true,
//                    "IsRequired" => false,
//                    "IsOnProductDetails" => true,
//                    "IsStockKeepingUnit" => (bool)$type,
//                    "IsActive" => true,
//                    "IsTopMenuLinkActive" => false,
//                    "IsSideMenuLinkActive" => false,
//                    "DefaultValue" => '',
//                ]
//            ]);
//            return json_decode($response->getBody()->getContents(), true);
//        } catch (\Exception $e) {
//            return false;
//        }
//    }

//    public function createSpecificationValue($fieldId, $value, $isActive = true, $position = 1)
//    {
//        $response = $this->client->get("api/catalog_system/pvt/specification/fieldValue", [
//            'json' => [
//                "FieldId" => (int)$fieldId,
//                "Name" => $value,
//                "Text" => $value,
//                "IsActive" => $isActive,
//                "Position" => $position
//            ]
//        ]);
//        return json_decode($response->getBody()->getContents(), true);
//    }

//    public static function getSpecificationFieldTypeId($name){
//        $types = [ 'Text' => 1, 'Multi-Line Text' => 2, 'Number' => 4, 'Combo' => 5, 'Radio' => 6, 'CheckBox' => 7, 'Indexed Text' => 8, 'Indexed Multi-Line Text' => 9];
//        if (array_key_exists($name,$types))
//            return $types[$name];
//        else
//            return 5;
//
//    }

//    public function getSpecificationGroupId($categoryId, $name)
//    {
//        if (!isset($groupIds[$categoryId][$name])) {
//            $specification = $this->getSpecificationsGroupByCategory($categoryId, true, $name);
//            $groupIds[$categoryId][$name] = $specification['Id'];
//        }
//        return $groupIds[$categoryId][$name];
//    }

//    public function getSpecificationsGroupByCategory($categoryId, $create = false, $groupName = "")
//    {
//        //facem try deoarece daca nu are grupe, rezulta 404
//        try {
//            $response = $this->client->get("api/catalog_system/pvt/specification/groupbycategory/$categoryId");
//            $groups = json_decode($response->getBody()->getContents(), true);
//            if ($groupName) {
//                if ($key = array_search($groupName, array_column($groups, 'Name')))
//                    return $groups[$key];
//                else
//                    if ($create)
//                        return $this->createSpecificationsGroup($categoryId, $groupName);
//            }
//            return $groups;
//        } catch (\Exception $exception) {
//            //daca nu are adaugam grupul
//            if ($create) {
//                if ($groupName)
//                    return $this->createSpecificationsGroup($categoryId, $groupName);
//                else
//                    return $this->createSpecificationsGroup($categoryId);
//            } else
//                return false;
//        }
//    }

//    public function createSpecificationsGroup($categoryId, $name = "Specificatii")
//    {
//        $response = $this->client->post("api/catalog_system/pvt/specification/group", [
//            'json' => [
//                'CategoryId' => $categoryId,
//                'Name' => $name
//            ]
//        ]);
//
//        return json_decode($response->getBody()->getContents(), true);
//    }

    /**
     * @param OrderInvoice $invoice
     * @return array
     */
    public function getInvoiceData($invoice)
    {
        $items = [];
        /** @var OrderDetail $item */
        foreach ($invoice->getProductsDetail() as $item) {
            $items[] = [
                'id' => $item['product_id'],
                'price' => (int)($item['unit_price_tax_incl'] * $this->vtexPriceMultiplier),
                'quantity' => $item['product_quantity']
            ];
        }

        $order = $invoice->getOrder();
        if (isset($order->secure_key) && !empty($order->secure_key))
            $this->context->smarty->assign('secure_key', $order->secure_key);

        return [
            'type' => 'Output',
            'invoiceNumber' => $invoice->number,
            'invoiceValue' => (int)($invoice->total_paid_tax_incl * $this->vtexPriceMultiplier),
            'issuanceDate' => date(DATE_ISO8601, strtotime($invoice->date_add)),
            'invoiceUrl' => HistoryControllerCore::getUrlToInvoice($order, $this->context)."&secure_key={$order->secure_key}",
            'items' => $items,
            'courier' => (new Carrier($order->id_carrier))->name,
            'trackingNumber' => '',
            'trackingUrl' => ''
        ];
    }
}
