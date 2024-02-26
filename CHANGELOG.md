<!--- BEGIN HEADER -->
# Changelog

All notable changes to this project will be documented in this file.
<!--- END HEADER -->

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

