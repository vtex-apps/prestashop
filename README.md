> ### This is a Prestashop 1.7 module
# VTEX seller connector for Prestashop 1.7

Using this custom connector will allow VTEX stores to become marketplaces themselves and sell products from an external prestashop seller.

## Installation Guide

### 1. Prestashop configuration

1) Install the module by either copying the files in the `modules/vtex_connector` folder or download as a zip file and upload it form `IMPROVE > Modules > Module Manager > Upload module`

2) In the prestashop admin section we navigate to `IMPROVE -> Modules -> Module Manager`, find the `VTEX Connector` module and click `Configure`.
   Here we fill out and save:
* `Marketplace account` (required) - represents the VTEX marketplace account name
* `App key` and `App token` (required) - required for the API calls made to the marketplace
* `Seller ID` (required) - used when configuring the seller in the marketplace


3) Generate a `PRESTASHOP_WEBSERVICE_KEY` by navigating to `CONFIGURE -> Advanced Parameters -> Webservice`.<br>
   Enable `Enable PrestaShop's webservice`, then click `Add new webservice key`, add/generate a `Key` and check all resources `Permissions`, then save.

### 2. VTEX configuration

Then we need to set up a new seller in the VTEX marketplace admin section.<br>
We navigate to `Marketplace -> Sellers -> Management` and click de `New seller` button.<br>
When prompted `Choose the type of Seller you want to register`, choose `Others`, fill out the form and save:
* `Seller ID` - is the `Seller ID` configured in prestashop
* `Other Name` - desired name
* `Admin Email` - an email address
* `Fulfillment EndPoint` - represents the seller endpoint (see bellow)
* make sure `Active?` is checked

The `Fulfillment EndPoint` should be `https://{prestashopBaseURl}/api/fulfillment?ws_key=PRESTASHOP_WEBSERVICE_KEY&io_format=JSON`<br>

#### <u>Marketplace trade policies</u>
In the VTEX marketplace got to `Store Setup -> Trade policies`, edit a desired policy, and next to `Sellers` check the newly created seller so that the policy will also apply to them.<br>
Here you can also set the currency to match the seller store, or change it in the seller store to match the policy.

#### <u>Shipping Rates</u>

In prestashop set up Handling charges by going to `IMPROVE > Shipping > Preferences`and editing `Handling charges`.

#### <u>Products specifications</u>

In order to correctly retrieve product specifications from Prestashop into VTEX, the following conditions must be met before approving new products:
* in VTEX, under `MARKETPLACE -> Sellers -> Categories and Brands` the `Specifications mapping` needs to be filled out.<br>
  On the `Seller` side will be the specification's label from Prestashop and on the `Marketplace` side will be the specification's VTEX name.<br>
* When you approve a new product with specifications from Prestashop, you need to make sure that those specifications exists on the VTEX category where you approve the product.<br>
* In VTEX, category specifications can be found under `PRODUCTS -> Catalog -> Categories`, select the category in question
  and from the `ACTIONS` tab select `Field (Sku)`.<br>
  Here you can edit or add a new specification.<br>
  Always make sure `Active` is checked, even after editing/creating a specification.
* The specification name is used in the `Marketplace` side when doing `Specifications mapping`.

<hr>

## How does it work?

Following the [external seller connector](https://developers.vtex.com/vtex-rest-api/docs/external-seller-integration-connector) documentation, there are nine different API requests. Five of these are calls that the seller should make to the marketplace. The other four are requests that the marketplace will need to make to the seller.

### 1. Catalog notification, registration, update

The seller, in this case prestashop, is responsible for suggesting new SKUs to be sold in the marketplace and also for informing the marketplace about changes in their SKUs that already exist in the marketplace.

When changing product information, in prestashop, the `actionProductUpdate` hook is triggered.<br>

Here the first step is to check if the sku exists in the marketplace by calling the [Change Notification endpoint](https://developers.vtex.com/vtex-rest-api/reference/catalog-api-sku-seller#catalog-api-get-seller-sku-notification) with two possible responses:
* Status 404, means that the SKU does not yet exist in the marketplace. The seller will push the SKU offer to the marketplace using [Send SKU Suggestion](https://developers.vtex.com/vtex-rest-api/reference/manage-suggestions-1)
* Status 200, means that the SKU exist in the marketplace. The marketplace will make a request to the seller to get updated sku information using [Fulfillment Simulation](https://developers.vtex.com/vtex-rest-api/reference/external-seller#fulfillment-simulation)

All SKUs provided by the [Send SKU Suggestion](https://developers.vtex.com/vtex-rest-api/reference/manage-suggestions-1) endpoint can be found in the store admin area under `Marketplace -> Sellers -> Received SKUs`.<br>
Once approved, the SKUs will become available in the `Products -> Catalog` section and from here on out only price and inventory will be updated via [Fulfillment Simulation](https://developers.vtex.com/vtex-rest-api/reference/external-seller#fulfillment-simulation).

During the order flow, the marketplace storefront needs to be constantly fetching the updated price and inventory of each SKU in the cart. This is essential to guarantee that the customer will always be presented with the most updated information possible. 
This information is provided by the seller through the [Fulfillment Simulation](https://developers.vtex.com/vtex-rest-api/reference/external-seller#fulfillment-simulation) endpoint.

### 2. Order placement and dispatching

Once the customer finishes their checkout, the marketplace needs to let the seller know there is a newly placed order through the [Order Placement](https://developers.vtex.com/vtex-rest-api/reference/external-seller#order-placement) endpoint. The marketplace will send all data required for the seller to be able to create the order in their own store.

After payment is approved, the marketplace sends a request to the seller through the [Ready For Handling](https://developers.vtex.com/vtex-rest-api/reference/external-seller#order-dispatching) endpoint, to notify it that the fulfillment process can be started.

### 3. Order invoicing and tracking

The invoice is issued by the seller, the invoice data must be sent to the marketplace. 
The seller sends this information through the [Order Invoice Notification](https://developers.vtex.com/vtex-rest-api/reference/invoice#invoicenotification) request.<br>
In prestashop, the call will be triggered on the `actionSetInvoice` hook.

When sending order tracking information the [Update Order's partial invoice](https://developers.vtex.com/vtex-rest-api/reference/invoice#updatepartialinvoicesendtrackingnumber) endpoint is called to update existing invoice with the tracking information.<br>
In prestashop, the call will be triggered on the `actionAdminOrdersTrackingNumberUpdate` hook.

### 4. Order cancellation 

The order can be cancelled by either the seller or the marketplace.

When the marketplace cancels an order, a request is made to the seller through the [Marketplace Order Cancellation](https://developers.vtex.com/vtex-rest-api/reference/external-seller#mkp-order-cancellation) endpoint.

When the seller (prestashop) cancels an order, the `actionOrderStatusPostUpdate` hook is triggered.<br>
If the order state is `canceled`, the seller makes a request to the seller through the [Cancel Order](https://developers.vtex.com/vtex-rest-api/reference/orders#cancelorder) endpoint.

### 5. Fulfillment EndPoints

The prestashop default REST endpoint is `https://{prestashopBaseURl}/api`.<br>
The following endpoints where added to prestashop in order to accept request from the marketplace:
* `/fulfillment/pvt/orderForms/simulation` - [Fulfillment Simulation](https://developers.vtex.com/vtex-rest-api/reference/external-seller#fulfillment-simulation)
* `/fulfillment/pvt/orders` - [Order Placement](https://developers.vtex.com/vtex-rest-api/reference/external-seller#order-placement)
* `/fulfillment/pvt/orders/:orderId/fulfill` -  [Ready For Handling](https://developers.vtex.com/vtex-rest-api/reference/external-seller#order-dispatching)
* `/fulfillment/pvt/orders/:orderId/cancel` - [Cancel Order](https://developers.vtex.com/vtex-rest-api/reference/orders#cancelorder)