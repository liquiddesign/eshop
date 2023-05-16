# Ⓔ Eshop - CHANGELOG

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0-beta.1]

### Added

- **BREAKING:** Many entities now have foreign key to `Base/DB/Shop` entity
    - To run this version of Eshop you need to sync this entity to database
    - Selects in forms to entities with Shop, are always shown all with description of Shop state
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
- **BREAKING:** Product content is not stored in Product but in ProductContent, based on Shop.

### Changed

- **BREAKING:** PHP version 8.2 or higher is required
- **BREAKING:** Comgate service is now provided only by Integrations service. Comgate package extension is still injected to *Container* with configuration.
- **BREAKING:** Many callbacks are now always arrays, so you need to call them with *Arrays::invoke*
- **BREAKING:** `CheckoutManager::addItemToCart` - parameter $checkInvalidAmount now accepts only enum `\Eshop\Common\CheckInvalidAmount`
- **BREAKING:** Dropped support for Latte <3.0
- **BREAKING:** Old XML exports are no longer available, V2 exports are now default.
- **BREAKING:** Property primaryCategory on Product is now removed, primary category of Product is determined with entity ProductPrimaryCategory, which is unique for Product by CategoryType
    - Product can have 0..1 primary categories in CategoryType
    - To access Product primary category, use getPrimaryCategory function
    - Function *ProductRepository::getProducts* selects primaryCategory property based on selected Shop and setting of CategoryType for that Shop
- **BREAKING:** All parts of Product CSV import/export are moved to separate services `ProductExporter` and `ProductImporter`
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
    - *OrderList::handleExport*
    - *OrderList::exportCsvApi*
    - *CheckoutManager::getCartCheckoutPriceBefore*
    - *CheckoutManager::getCartCheckoutPriceVatBefore*
    - *CouponForm::validateCoupon*
    - *CatalogPermission::$newsletter*
    - *CatalogPermission::$newsletterGroup*
    - *Customer::$newsletter*
    - *Customer::$newsletterGroup*
    - *Customer::$pricesWithVat*
    - *Category::getFallbackImage*
    - *CategoryRepository::updateCategoryChildrenPath*
    - *CategoryRepository::doUpdateCategoryChildrenPath*
    - *CategoryRepository::getCountsGrouped*
    - *CategoryRepository::getProducerPages*
    - *CustomerGroupRepository::getListForSelect*
    - *CustomerRepository::getListForSelect*
    - *DiscountCouponRepository::getValidCoupon*
    - *Order::getInvoices*
    - *OrderRepository::getFinishedOrdersByCustomer*
    - *OrderRepository::getNewOrdersByCustomer*
    - *OrderRepository::cancelOrderById*
    - *OrderRepository::banOrderById*
    - *Algolia::uploadProducts*
    - *ProductRepository::getDisplayAmount*
    - *ProductRepository::filterTag*
    - *ProductRepository::getProductAsGroup*
    - *Product::getPreviewParameters*
    - *Product::$upsells*
    - *Product::$rating*
    - *Product::$primaryCategory*
    - *ExportPresenter::ERROR_MSG*
      - The error message now shows the actual error.
### Deprecated

- *Integration/MailerLite*

### Fixed

- Stores are no longer deleted during catalogEntry, only synced
- Fixed indexes on PackageItem

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
