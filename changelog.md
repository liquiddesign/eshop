# Ⓔ Eshop - CHANGELOG

## [1.3.0 - 1.3.4] - 2023-03-26

### Added
- **BREAKING:** New Shopper option *autoFixCart*, defaults to **true**
  - If enabled, CartChecker is not shown and all changes to cart are immediately applied
- CheckoutManager::addItemToCart - parameter $checkInvalidAmount now accepts *bool|null|\Eshop\Common\CheckInvalidAmount*, more info in function doc
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
- CheckoutManager::addItemsFromCart - fixed adding related items that no longer exists
