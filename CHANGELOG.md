<!--- BEGIN HEADER -->
# Changelog

All notable changes to this project will be documented in this file.
<!--- END HEADER -->

## [2.1.157](https://github.com/liquiddesign/eshop/compare/v2.1.156...v2.1.157) (2024-03-21)

### ⚠ BREAKING CHANGES


##### Comgate

* Change calculation of total price with vat to truly reflect price ([a55f3c](https://github.com/liquiddesign/eshop/commit/a55f3c0a76b1459444d41645df4f3b10e8a9feae))

### Features


##### Comgate

* Add ability to extend total price calculation ([c6b613](https://github.com/liquiddesign/eshop/commit/c6b613b973374cd5667f8ddc1ba2eb89e89da206))

### Bug Fixes

* Product csv import ([eeffb4](https://github.com/liquiddesign/eshop/commit/eeffb4280f82615c6ba64f2d332a3b7ffc35e3ad))


---

## [2.1.156](https://github.com/liquiddesign/eshop/compare/v2.1.155...v2.1.156) (2024-03-20)

### Features


##### Admin- Product-CSV

* Strip URL with starting slash ([7c7cf9](https://github.com/liquiddesign/eshop/commit/7c7cf9055273085254b787d724754073036ee1e2))
* Label ([0aa52d](https://github.com/liquiddesign/eshop/commit/0aa52d74c249785469db0ce34c0c848b7284f549), [f29463](https://github.com/liquiddesign/eshop/commit/f29463a8331db9bb3126a7630dce3c5ec52d4b4b))


---

## [2.1.155](https://github.com/liquiddesign/eshop/compare/v2.1.154...v2.1.155) (2024-03-20)

### Features


##### Admin- Product-CSV

* New import/export of mutations ([f8a089](https://github.com/liquiddesign/eshop/commit/f8a089c458a1b7c89a3151a82faf394a78fd1ad1))


---

## [2.1.154](https://github.com/liquiddesign/eshop/compare/v2.1.153...v2.1.154) (2024-03-19)

### ⚠ BREAKING CHANGES

* Import files are no longer forced converted to UTF8, because conversion is not reliable. Import is assuming your file is in correct encoding. ([a7b966](https://github.com/liquiddesign/eshop/commit/a7b9669fd84854e9d8b23aeef0e57f4c811663b4))
* Remove dependency on neitanod/forceutf8 ([a7b966](https://github.com/liquiddesign/eshop/commit/a7b9669fd84854e9d8b23aeef0e57f4c811663b4))


---

## [2.1.153](https://github.com/liquiddesign/eshop/compare/v2.1.152...v2.1.153) (2024-03-19)

### Bug Fixes


##### Delivery Payment Form

* Change visibility ([0674af](https://github.com/liquiddesign/eshop/commit/0674af0dfa6e98ed51eb4b42af3e24ea96f25d6c))


---

## [2.1.152](https://github.com/liquiddesign/eshop/compare/v2.1.151...v2.1.152) (2024-03-19)

### Features

* Changes from v1.5 ([e4b2a3](https://github.com/liquiddesign/eshop/commit/e4b2a37d382d9a07f831aae457c5a5f854a13194))

##### Category

* BulkEdit defaultViewType ([10f7db](https://github.com/liquiddesign/eshop/commit/10f7db5f5187f49e0b608b441e9a0abd5c411b42))


---

## [2.1.151](https://github.com/liquiddesign/eshop/compare/v2.1.150...v2.1.151) (2024-03-18)

### Features


##### Category

* Add defaultViewType ([31db2f](https://github.com/liquiddesign/eshop/commit/31db2f58d44cc990843eb4012dfc8c2ec378b7e3))

### Bug Fixes


##### Admin- Attributes

* Show range link only if range tab is available ([1e2129](https://github.com/liquiddesign/eshop/commit/1e2129d930ce71b03aa7590eecbba506eb24f991))

##### Products Cache

* Throw exception in warm up ([aecfe9](https://github.com/liquiddesign/eshop/commit/aecfe9c1759786da9e798e039df09cd1972582b3))


---

## [2.1.150](https://github.com/liquiddesign/eshop/compare/v2.1.149...v2.1.150) (2024-03-11)

### Bug Fixes


##### Supplier Product

* SyncDisplayAmounts was not functioning properly when Product has no SupplierProduct ([480ab7](https://github.com/liquiddesign/eshop/commit/480ab787de5910a138cfedb63ea96ce54dde7615))


---

## [2.1.149](https://github.com/liquiddesign/eshop/compare/v2.1.148...v2.1.149) (2024-03-11)

### Bug Fixes

* Change grid filters to new datetime polyfills ([c1677c](https://github.com/liquiddesign/eshop/commit/c1677c4a673268e2014db0663d5c7257091f1c19))


---

## [2.1.148](https://github.com/liquiddesign/eshop/compare/v2.1.147...v2.1.148) (2024-03-07)


---

## [2.1.147](https://github.com/liquiddesign/eshop/compare/v2.1.146...v2.1.147) (2024-03-07)

### Features


##### Pricelist

* Add a description to the price list. ([5ae6b7](https://github.com/liquiddesign/eshop/commit/5ae6b7423522a2c1c050bdd50354b8e8971f4402))

### Styles

* Fix ([00d598](https://github.com/liquiddesign/eshop/commit/00d598c5fa1c1ffe2746a6e0ef938fa69a2fa15d))

### Builds

* Update nette forms and other dependence ([8798b0](https://github.com/liquiddesign/eshop/commit/8798b0f1e1c37d98141e5d27f757c51fec6b5ca3))


---

## [2.1.146](https://github.com/liquiddesign/eshop/compare/v2.1.145...v2.1.146) (2024-03-04)

### Features


##### Products Cache

* Disable PriceList discounts, better cache update, show index in Customer detail ([1f9264](https://github.com/liquiddesign/eshop/commit/1f926400ccd71901da33af799615007e606031e1))


---

## [2.1.145](https://github.com/liquiddesign/eshop/compare/v2.1.144...v2.1.145) (2024-03-04)

### Features


##### Products Cache

* Add ability to update cache indices of selected Customers of CustomerGroups ([93efc1](https://github.com/liquiddesign/eshop/commit/93efc137dc13d7a67e1cbebec58b5174ec358b8f))


---

## [2.1.144](https://github.com/liquiddesign/eshop/compare/v2.1.143...v2.1.144) (2024-02-29)

### Bug Fixes


##### Order Edit Service

* Adding products fixed ([752bc4](https://github.com/liquiddesign/eshop/commit/752bc4ce74d7fbb33963bae3d5425decc0b04a60))


---

## [2.1.143](https://github.com/liquiddesign/eshop/compare/v2.1.142...v2.1.143) (2024-02-28)

### Bug Fixes

* Hide bad column ([098998](https://github.com/liquiddesign/eshop/commit/098998a1af087b52155a2f31cef770f1d70cd3de))


---

## [2.1.142](https://github.com/liquiddesign/eshop/compare/v2.1.141...v2.1.142) (2024-02-28)

### Code Refactoring

* Created new OrderEditService ([cba390](https://github.com/liquiddesign/eshop/commit/cba3905127d95e901bef8779e2300f40a528f771))


---

## [2.1.141](https://github.com/liquiddesign/eshop/compare/v2.1.140...v2.1.141) (2024-02-26)

### Features


##### Product Repository

* Make isProductDeliveryFree public, change parameters to optional and set them default value, rewrite isProductDeliveryFree to correctly get discounts ([80a412](https://github.com/liquiddesign/eshop/commit/80a412eafd6a3f6e643e6314d173efaf27396138))


---

## [2.1.140](https://github.com/liquiddesign/eshop/compare/v2.1.139...v2.1.140) (2024-02-26)

### ⚠ BREAKING CHANGES

* Revert Dependency injection aggregates ([e02451](https://github.com/liquiddesign/eshop/commit/e02451f597d5a4fc675cdad8a5a633158d075e25))


---

## [2.1.139](https://github.com/liquiddesign/eshop/compare/v2.1.138...v2.1.139) (2024-02-26)

### ⚠ BREAKING CHANGES

* Remove old products providers ([68393e](https://github.com/liquiddesign/eshop/commit/68393e61b97053453dda18bcf6feffd3505a22b8))


---

## [2.1.138](https://github.com/liquiddesign/eshop/compare/v2.1.137...v2.1.138) (2024-02-23)

### Builds

* New approach of DependencyAggregates to inject services ([02a038](https://github.com/liquiddesign/eshop/commit/02a03837386d6284cb4a052732b24364b49f3c44))


---

## [2.1.137](https://github.com/liquiddesign/eshop/compare/v2.1.136...v2.1.137) (2024-02-22)

### Features


##### Admin- Customer

* Add getBulkEdits to add option to extend $bulkEdits ([cc7d0c](https://github.com/liquiddesign/eshop/commit/cc7d0c18448855d3c3b791de955a90da2db53765))

##### Order

* Add option fillProfile, show in order detail ([f7f95f](https://github.com/liquiddesign/eshop/commit/f7f95f638042d5104394626d96864c66f3365058))

### Code Refactoring


##### Admin Order

* Extract change of amount ([9e11b1](https://github.com/liquiddesign/eshop/commit/9e11b1785a3a70180d772a40c64e625bdf131a7b))


---

## [2.1.136](https://github.com/liquiddesign/eshop/compare/v2.1.135...v2.1.136) (2024-02-20)

### Bug Fixes


##### Checkout Manager:create Customer

* Assign visibility lists to new customer from customer group ([c2b4e4](https://github.com/liquiddesign/eshop/commit/c2b4e499ffd70c74dbb0ef0aa353d5a792203132))


---

## [2.1.135](https://github.com/liquiddesign/eshop/compare/v2.1.134...v2.1.135) (2024-02-20)

### Performance Improvements


##### Product Exporter

* Log peak memory ([e9d94e](https://github.com/liquiddesign/eshop/commit/e9d94e15875326290f3ef574395f562228cc1710))


---

## [2.1.134](https://github.com/liquiddesign/eshop/compare/v2.1.133...v2.1.134) (2024-02-18)

### Chores

* New changelog system ([8347ab](https://github.com/liquiddesign/eshop/commit/8347abbdc59a015af2d6d14efb6878a9ea723703))
* Clear changelogs ([fc7b90](https://github.com/liquiddesign/eshop/commit/fc7b9025a545e334559614e25d473b4612fe12a3))


---

