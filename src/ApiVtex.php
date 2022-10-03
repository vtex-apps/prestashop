<?php

class ApiVtex extends Vtex
{
    protected $api_endpoint = "https://api.vtex.com/{{vendor}}/";

    public function sendSKUSuggestion(Product $product)
    {
        $body = $this->getProductData($product);

        if (count($body['Images'])) {
            try {
                $this->client->put("suggestions/{$this->sellerId}/{$product->id}", [
                    'json' => $body
                ]);
                return true;
            } catch (\Exception $e) {
                return false;
            }
        }
    }

    public function getProductData(Product $product)
    {
        $categoryName = 'NONAME';
        if ($product->id_category_default) {
            $categoryName = (new Category($product->id_category_default))->getName();
        }

        $brand = 'NONAME';
        if ($product->id_manufacturer) {
            $brand = (new Manufacturer($product->id_manufacturer))->name;
        }

        $sku = $product->mpn ?: "{$this->sellerId}-{$product->id}";

        $productName = is_array($product->name) ? $product->name[1] : $product->name;
        $name = strlen($productName) > 127 ? substr($productName, 0, 127) : $productName;

        return [
            'ProductName' => $name,
            'ProductId' => $product->id,
            'ProductDescription' => is_array($product->description) ? $product->description[1] : $product->description,
            'BrandName' => $brand,
            'SkuName' => $name,
            'SellerId' => $this->sellerId,
            'Height' => 1,
            'Width' => 1,
            'Length' => 1,
            'WeightKg' => $product->weight ?: 1,
            'RefId' => $sku,
            'SellerStockKeepingUnitId' => $product->id,
            'CategoryFullPath' => $categoryName,
            'SkuSpecifications' => $this->getSpecifications($product),
            'ProductSpecifications' => $this->getSpecifications($product),
            'Images' => $this->getImages($product),
            'MeasurementUnit' => 'un',
            'UnitMultiplier' => 1,
            'AvailableQuantity' => $product->quantity ?: 0,
            'Pricing' => [
                'Currency' => Currency::getDefaultCurrency()->iso_code,
                'SalePrice' => $product->getPrice(),
                'CurrencySymbol' => Currency::getDefaultCurrency()->sign,
            ],
        ];
    }

    public function getImages(Product $product)
    {
        $response = [];

        if ($images = $product->getImages($this->context->language->id)) {
            foreach ($images as $image) {
                $response[] = [
                    'imageName' => "Image{$image['position']}",
                    'imageUrl' => $this->context->link->getImageLink($product->link_rewrite, $image['id_image'], 'home_default')
                ];
            }
        }

        return $response;
    }

    public function getSpecifications(Product $product, $detailed = false)
    {
        $response = [];
        $features = Product::getFrontFeaturesStatic($this->context->language->id, $product->id);

        foreach ($features as $feature) {
            $type = 'Combo';
            $value = $feature['value'];
//            if ($value instanceof \Magento\FrameWork\Phrase) {
//                $value = $value->getText();
//                $type = 'Radio';
//            }

            $fields = [
                'FieldName' => $feature['name'],
                'FieldValues' => [$value],
            ];

            if ($detailed) {
                $fields['Type'] = $type;
            }

                $response[] = $fields;
        }

        return $response;
    }
}
