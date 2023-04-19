# Ⓔ Eshop - CHANGELOG

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0]

### Added
- **BREAKING:** New *ShopperUser* and *CheckoutManager*
  - *ShopperUser* is now extending *Nette\Security\User* and act like it
  - *CheckoutManager* is now not injected to *Container* and is available only by *ShopperUser*
  - *ShopperUser* should not use *CheckoutManager* directly
  - *CheckoutManager* uses *ShopperUser*
### Changed
- **BREAKING:** Comgate service is now provided only by Integrations service. Comgate package extension is still injected to *Container* with configuration.
- **BREAKING:** Many callbacks are now always arrays, so you need to call them with *Arrays::invoke*
### Removed
- **BREAKING:** Removed deprecated classes
  - `FrontendPresenter`
  - `ComgatePresenter`
  - `ExportPresenter`
  - `DB/Tag`
  - `DB/TagRepository`
  - `Admin/TagPresenter`
  - `Integration/Comgate`
  - `Admin/CustomerGroupPresenter`
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
  - `DB/SupplierParameterValue.php`
  - `DB/SupplierParameterValueRepository.php`
### Deprecated
 - **BREAKING:** *Shopper* and *CheckoutManager* are now deprecated and not injected to *Container*
   - Use *ShopperUser* and *CheckoutManager* from *ShopperUser* instead
   - This change is not backward compatible, you need to rewrite all dependencies to *Shopper* and *CheckoutManager*
### Fixed

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
