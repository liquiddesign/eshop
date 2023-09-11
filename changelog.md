# Ⓔ Eshop - CHANGELOG

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.1.22] - 2023-09-11

### Changed
- Category::getProductCount
  - Added new filter options to truly reflect what products are shown
  - Counting is now done via ProductsProvider, which is significantly faster
  - Counts are now cached by Category path

## [2.1.21] - 2023-09-11

### Added
- ProductsProvider - additional caching with Nette Cache

### Changed
- Removed unnecessary dumps
- Improved admin control over cache

## [2.1.20] - 2023-09-10

### Added
- Simple ProductTester to test visibility of product

## [2.1.4] - 2023-08-29

### Added

- New ProductsProvider for high-performance caching and loading of products with semi-dynamic filtering.
  - **BREAKING:** Multiple entities now uses integer autoincrement for unique index. This has better performance when using select.
    - **You have to run migration _20230812-v2_cache.sql_**
  - ProductsProvider uses two sets of own cache + every request is indexed in Nette cache.
  - During cache warmup second cache is used and after fully warmed up first cache is flipped and vice versa.
  - Supports full counting of attributes, dynamic filtering of OR attributes and price with minimal impact on performance.
  - **ProductsProvider has limited options of filtering and ordering, so check if your project is suitable for this cache!**
    - If the table containing cache states is empty, products will be loaded using the legacy method.
- ShopperUser now has ability to choose selected Customer via session
    - This adds option to log in as Customer, but act like child Customer (using his PriceLists, VisibilityLists and make order as him)
    - CatalogPermission is still used from original Customer

### Changed

- **BREAKING:** ProfileForm
  - Email input is nullable instead of required
  - Addresses now has all possible inputs
- Updated packages versions

## [2.0.0] - 2023-07-12

### Changed

- PriceLists and VisibilityLists can be created for specific shop or for all shops.
- Various import changes

### Fixed

- Carts fixes
- Reviews creation on order creation fix

## [2.0.0-beta.1] - 2023-07-10

### Added

- **BREAKING:** Many entities now have foreign key to `Base/DB/Shop` entity
    - To run this version of Eshop you need to sync this entity to database
    - Some entities are saved to Shop, which is currently selected. For others, you can select Shp by yourself.
- **BREAKING:** New *ShopperUser* and *CheckoutManager*
    - *ShopperUser* is now extending *Nette\Security\User* and act like it
    - *CheckoutManager* is now not injected to *Container* and is available only by *ShopperUser*
    - *ShopperUser* should not use *CheckoutManager* directly
    - *CheckoutManager* uses *ShopperUser*
- **BREAKNG:** New Visibility system for Products
    - Properties hidden, hiddenInMenu, unavailable, recommended and priority are no longer stored in Product but in VisibilityListItem
    - These properties are now stored in *VisibilityListItem* which always have *VisibilityList*
    - Selection is same as Pricelists and Prices selection:
        - *VisibilityLists* are assigned to groups and customers
        - *VisibilityList* has priority, same as Pricelist
        - *VisibilityListItem* is selected based on assigned *VisibilityList*s to customer or group, and is found first product row based on priority of *VisibilityList*
        - You can use *ProductRepository::joinVisibilityListItemToProductCollection* to join them
            - *ProductRepository::getProducts* does this automatically and properties are available in Product getters, or directly in SQL as alias *visibilityListItem*
- **BREAKING:** Product content (content, perex) is not stored in Product but in ProductContent, based on Shop.

- You can pass parameter *lastOrder* of `CheckoutManager::createOrder`. Default is true. It can allow you to create a set of orders
  (just calling multiple createOrder in loop).
  With last order please pass parameter with *true* value. Following changes they are based on the need to create a set of orders.
- You can specify cart of `CheckoutManager::createOrder` in parameter with name *cart*.
- In following previous change you can call `getTopLevelItems` and `getItems` of `CheckoutManager` with *cart* parameter.
- You can now specify purchase discount apply directly to purchase and one time discount whole order without discount every cart item
- You can now choose cart in almost every method in checkoutManager and use multiple carts

### Changed

- **BREAKING:** PHP version 8.2 or higher is required
- **BREAKING:** Dropped support for Latte <3.0
- **BREAKING:** Comgate service is now provided only by Integrations service. Comgate package extension is still injected to *Container* with configuration.
- **BREAKING:** Many callbacks are now always arrays, so you need to call them with *Arrays::invoke*
- **BREAKING:** `CheckoutManager::addItemToCart` - parameter $checkInvalidAmount now accepts only enum `\Eshop\Common\CheckInvalidAmount`
- **BREAKING:** Old XML exports are no longer available, V2 exports are now default.
- **BREAKING:** Property primaryCategory on Product is now removed, primary category of Product is determined with entity ProductPrimaryCategory, which is unique for Product by CategoryType
    - Product can have 0..1 primary categories in CategoryType
    - To access Product primary category, use getPrimaryCategory function
    - Function *ProductRepository::getProducts* selects primaryCategory property based on selected Shop and setting of CategoryType for that Shop
- **BREAKING:** All parts of Product CSV import/export are moved to separate services `ProductExporter` and `ProductImporter`
    - Old CSV files are not compatible with new import
    - Imports/exports reflects changes in content, visibility and categories
    - Content, Perex and Categories are no longer in 'importColumns' array in configuration. They are now hard-coded in import/export services.
        - **BREAKING:** You NEED to remove them from you local configurations
- **BREAKING:** `SupplierCategory` now can have multiple paired Categories
- **BREAKING:** `SupplierProductRepository::syncProducts` overhauled to reflect all changes
    - **BREAKING:** Content (name, content, perex) import option "with the longest content" is removed
    - **BREAKING:** Content is regardless of import option imported only if content of product is empty
- **BREAKING:** `Product::toArray` now accepts `$shop` and `$selectContent` parameters. `$selectContent` defaults to true, so it selects content, even if it is not loaded. That can lead to performance
  issue, so always check how you use this.
- **BREAKING:** `ProductRepository::getProduct` parameter *productUuid* renamed to *$condition*. Now it is union type accepts array for *whereMatch* or exact *uuid*.
- **BREAKING:** changed behavior of `CheckoutManager::createCart`
    - If *active* parameter is false. No longer triggers event *onCartCreate*.
    - If *active* parameter is false. Property *activeCart* in shopper customer s not updated.
- XML exports accepts Shop parameter and used entities are affected by it.
- PriceList selects in XML exports now shows all PriceLists, even from different Shops. Truly active PriceLists are filtered afterward in exports.
- Category code must be unique within the CategoryType

### Removed

- **BREAKING:** Properties hidden, hiddenInMenu, unavailable, recommended and priority are no longer stored in Product but in VisibilityListItem
    - Use new getters or repository function *ProductRepository::joinVisibilityListItemToProductCollection* to join them to Product collection (better performance then getters)
- **BREAKING:** Removed deprecated classes
    - `Shopper`
    - `FrontendPresenter`
    - `ComgatePresenter`
    - `ExportPresenter`
    - `DB/Tag`
    - `DB/TagRepository`
    - `Admin/TagPresenter`
    - `Integration/Comgate`
    - `Admin/CustomerGroupPresenter`
    - `Admin/ParameterPresenter`
    - `DB/Parameter`
    - `DB/ParameterAvailableValue`
    - `DB/ParameterAvailableValueRepository`
    - `DB/ParameterCategory`
    - `DB/ParameterCategoryRepository`
    - `DB/ParameterGroup`
    - `DB/ParameterGroupRepository`
    - `DB/ParameterRepository`
    - `DB/ParameterValue`
    - `DB/ParameterValueRepository`
    - `DB/Set`
    - `DB/SetItem`
    - `DB/SetItemRepository`
    - `DB/SetRepository`
    - `DB/SupplierParameterValue`
    - `DB/SupplierParameterValueRepository`
    - `DB/ProductTab`
    - `DB/ProductTabRepository`
    - `DB/ProductTabText`
    - `DB/ProductTabTextRepository`
- **BREAKING:** Removed many deprecated and unused functions and properties. Not all of them are listed here.
    - `OrderList::handleExport`
    - `OrderList::exportCsvApi`
    - `CheckoutManager::getCartCheckoutPriceBefore`
    - `CheckoutManager::getCartCheckoutPriceVatBefore`
    - `CouponForm::validateCoupon`
    - `CatalogPermission::$newsletter`
    - `CatalogPermission::$newsletterGroup`
    - `Customer::$newsletter`
    - `Customer::$newsletterGroup`
    - `Customer::$pricesWithVat`
    - `Category::getFallbackImage`
    - `CategoryRepository::updateCategoryChildrenPath`
    - `CategoryRepository::doUpdateCategoryChildrenPath`
    - `CategoryRepository::getCountsGrouped`
    - `CategoryRepository::getProducerPages`
    - `CustomerGroupRepository::getListForSelect`
    - `CustomerRepository::getListForSelect`
    - `DiscountCouponRepository::getValidCoupon`
    - `Order::getInvoices`
    - `OrderRepository::getFinishedOrdersByCustomer`
    - `OrderRepository::getNewOrdersByCustomer`
    - `OrderRepository::cancelOrderById`
    - `OrderRepository::banOrderById`
    - `Algolia::uploadProducts`
    - `ProductRepository::getDisplayAmount`
    - `ProductRepository::filterTag`
    - `ProductRepository::getProductAsGroup`
    - `Product::getPreviewParameters`
    - `Product::$upsells`
    - `Product::$rating`
    - `Product::$primaryCategory`
    - `ExportPresenter::ERROR_MSG`
        - The error message now shows the actual error.
    - `Product::SUPPLIER_CONTENT_MODE_LENGTH`

### Deprecated

- `Integration/MailerLite`

### Fixed

- Stores are no longer deleted during catalogEntry, only synced
- Fixed indexes on PackageItem

## [1.4.3] - 2023-06-23

### Changed

- **BREAKING:** When changing amount of item in `CartItemList` and new value is <=0, value was changed to 1. Now, instead of changing value, item is deleted from cart.
    - Check your usage before updating to this version! GTM can negatively affect this change.

## [1.4.0] - 2023-06-12

### Changed

- **BREAKING:** `DeliveryPaymentForm` changed properties and methods visibility

## [1.3.32] - 2023-05-30

### Changed

- **BREAKING:** `ApiGeneratorPresenter` changed generator response type from JSON to PLAIN TEXT

## [1.3.0 - 1.3.4] - 2023-03-26

### Added

- **BREAKING:** New Shopper option `autoFixCart`, defaults to **true**
    - If enabled, CartChecker is not shown and all changes to cart are immediately applied
    - If you are using CheckoutPresenter from package you are good to go, just hide CartChecker in template
        - If not you need to add `$this->shopperUser->getCheckoutManager()->autoFixCart();` at top of `startUp` function
- `CheckoutManager::addItemToCart` - parameter $checkInvalidAmount now accepts `bool|null|\Eshop\Common\CheckInvalidAmount`, more info in function doc
- ProfileForm - duplicate email validation added

### Changed

- **BREAKING:** DiscountCoupon code now must be unique. Before it had to be unique only to Discount, but that causes unpredictable behavior in case of same codes.
- DiscountCoupons validation of original cart price
- CouponForm - If coupon is applied, you can not change it. You must remove it from cart at first.
- Precision for prices computation in Admin is now 4 decimal places

### Removed

- ProfileForm - removed sending of email in case of email change - now it is responsibility of project

### Deprecated

- Pricelists in Shopper are no longer cached during request. DiscountCoupon is applied and removed during request and that can cause change of available pricelists

### Fixed

- `CheckoutManager::addItemsFromCart` - fixed adding related items that no longer exists
