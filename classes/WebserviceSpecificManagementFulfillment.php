<?php


class WebserviceSpecificManagementFulfillment implements WebserviceSpecificManagementInterface
{
    /** @var WebserviceOutputBuilder */
    protected $objOutput;

    /** @var WebserviceRequest */
    protected $wsObject;

    protected $output;

    public function getWsObject()
    {
        return $this->wsObject;
    }

    public function getObjectOutput()
    {
        return $this->objOutput;
    }

    /**
     * This must be return a string with specific values as WebserviceRequest expects.
     *
     * @return string
     */
    public function getContent()
    {
        return json_encode($this->output, JSON_UNESCAPED_UNICODE);
    }

    public function setWsObject(WebserviceRequestCore $obj)
    {
        $this->wsObject = $obj;
        return $this;
    }

    /**
     * @param WebserviceOutputBuilderCore $obj
     * @return WebserviceSpecificManagementInterface
     */

    public function setObjectOutput(WebserviceOutputBuilderCore $obj)
    {
        $this->objOutput = $obj;
        $this->objOutput->setHeaderParams('Content-Type', 'application/json; charset=utf-8');
        return $this;
    }

    public function manage()
    {
        $originalSegment = $this->wsObject->urlSegment;
        array_shift($originalSegment);
        $route = implode('/', $originalSegment);
        $webServiceKey = $this->wsObject->urlFragments['ws_key'];

        switch ($route) {
            case 'pvt/orderForms/simulation':
                if (in_array($this->wsObject->method, array('POST'))) {
                    $this->simulation();
                } else {
                    throw new WebserviceException('This method is not allowed.', 405);
                }
                break;
            case 'pvt/orders':
                if (in_array($this->wsObject->method, array('POST'))) {
                    $this->createOrder($webServiceKey);
                } else {
                    throw new WebserviceException('This method is not allowed.', 405);
                }
                break;
            default:
                if (in_array($this->wsObject->method, array('POST'))) {
                    if (preg_match('/pvt\/orders\/(.*)\/fulfill/', $route, $matches)) {
                        $this->fulfillOrder($matches[1]);
                    } elseif (preg_match('/pvt\/orders\/(.*)\/cancel/', $route, $matches)) {
                        $this->cancelOrder($matches[1]);
                    } else {
                        throw new WebserviceException('Not found', 400);
                    }
                } else {
                    throw new WebserviceException('This method is not allowed.', 405);
                }
        }
    }

    protected function simulation()
    {
        $request = json_decode($GLOBALS['input_xml'], true);
        $productHelper = new ProductHelper();

        $this->output = [
            'country' => $request['country'],
            'postalCode' => str_replace('-', '', $request['postalCode']),
            'geoCoordinates' => $request['geoCoordinates'],
            'pickupPoints' => [],
            'messages' => [],
            'items' => $productHelper->getRequestProducts($request),
            'logisticsInfo' => $productHelper->getLogisticsInfo($request)
        ];
    }

    protected function createOrder($webServiceKey)
    {
        $output = [
            'success' => false,
            'message' => 'Invalid data'
        ];

        $request = json_decode($GLOBALS['input_xml'], true);

        if (!isset($request[0])) {
            $output['message'] = 'Wrong data!';
        } else {
            $orderHelper = new OrderHelper();
            $vtexOrder = $request[0];

            if ($psOrderId = $orderHelper->create($vtexOrder, $webServiceKey)) {
                $output = [
                    [
                        'marketplaceOrderId' => $vtexOrder['marketplaceOrderId'],
                        'orderId' => (int)$psOrderId,
                        'followUpEmail' => $vtexOrder['clientProfileData']['email'],
                        'items' => $vtexOrder['items'],
                        'clientProfileData' => $vtexOrder['clientProfileData'],
                        'shippingData' => $vtexOrder['shippingData'],
                        'paymentData' => null
                    ]
                ];
            }
        }

        PrestaShopLogger::addLog("VTEX order response: ".json_encode($output), 1);
        $this->output = $output;
    }

    protected function fulfillOrder($marketplaceOrderId)
    {
        $orderHelper = new OrderHelper();
        if ($orderId = $orderHelper->getOrderId($marketplaceOrderId)) {
            $history = new OrderHistory();
            $history->id_order = $orderId;
            $history->changeIdOrderState(3, $orderId);
        }

        $this->output = [
            'marketplaceOrderId' => $marketplaceOrderId,
            'date' => date('Y-m-d H:i:s'),
            'orderId' => $orderId,
            'receipt' => null
        ];
    }

    protected function cancelOrder($sellerOrderId)
    {
        $orderHelper = new OrderHelper();
        if ($vtexOrderId = $orderHelper->getVtexOrderId($sellerOrderId)) {
            $history = new OrderHistory();
            $history->id_order = (int)$sellerOrderId;
            $history->changeIdOrderState(6, (int)$sellerOrderId);
        }

        $this->output = [
            'marketplaceOrderId' => $vtexOrderId,
            'date' => date('Y-m-d H:i:s'),
            'orderId' => (int)$sellerOrderId,
            'receipt' => null
        ];
    }
}