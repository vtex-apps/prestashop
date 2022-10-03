<?php

class ProductHelper
{
    protected $sellerId;
    protected $context;
    protected $vtexPriceMultiplier = 100;

    public function __construct()
    {
        $this->sellerId = Configuration::get('seller_id');
        $this->context = Context::getContext();
    }

    public function getRequestProducts($request)
    {
        $response = [];
        foreach ($request['items'] as $k => $item) {
            $product = new Product($item['id']);
            $product->loadStockData();
            $price = $product->getPrice() * $this->vtexPriceMultiplier;

            $response[] = [
                'id' => $product->id,
                'requestIndex' => $k,
                'quantity' => $item['quantity'],
                'price' => (int)$price,
                'listPrice' => (int)$price,
                'sellingPrice' => (int)$price,
                'measurementUnit' => 'un',
                'merchantName' => null,
                'priceValidUntil' => null,
                'seller' => $this->sellerId,
                'unitMultiplier' => 1,
                'attachmentOfferings' => [],
                'offerings' => [],
                'priceTags' => [],
                'availability' => ($product->quantity > 0) ? 'available' : 'unavailable',
            ];
        }
        return $response;
    }

    public function getLogisticsInfo($request)
    {
        $deliveryPrice = Configuration::get('PS_SHIPPING_HANDLING')  * $this->vtexPriceMultiplier;

        $response = [];
        foreach ($request['items'] as $k => $item) {
            $product = new Product($item['id']);
            $product->loadStockData();

            $response[] = [
                'itemIndex' => $k,
                'quantity' => $item['quantity'],
                'stockBalance' => $product->quantity ?: 0,
                'shipsTo' => [
                    isset($request['country']) ? $request['country'] : null
                ],
                'slas' => [
                    [
                        'id' => 'Normal',
                        'deliveryChannel' => 'delivery',
                        'name' => 'Normal',
                        'shippingEstimate' => '1bd',
                        'price' => $deliveryPrice / count($request['items']),
                    ]
                ],
                'deliveryChannels' => [
                    [
                        'id' => 'delivery',
                        'stockBalance' => $product->quantity ?: 0
                    ]
                ]
            ];
        }
        return $response;
    }
}
