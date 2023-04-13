# Ⓔ Eshop - CHANGELOG

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0]

### Added
### Changed
- Comgate service is now provided only by Integrations service.
### Removed
- Removed deprecated classes
  - `FrontendPresenter.php`
  - `ComgatePresenter.php`
  - `ExportPresenter.php`
  - `DB/Tag.php`
  - `DB/TagRepository.php`
  - `Admin/TagPresenter.php`
  - `Integration/Comgate.php`
  - ``
### Deprecated
### Fixed

## [1.3.0 - 1.3.4] - 2023-03-26

### Added
- **BREAKING:** New Shopper option `autoFixCart`, defaults to **true**
  - If enabled, CartChecker is not shown and all changes to cart are immediately applied
  - If you are using CheckoutPresenter from package you are good to go, just hide CartChecker in template
    - If not you need to add `$this->checkoutManager->autoFixCart();` at top of `startUp` function
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
