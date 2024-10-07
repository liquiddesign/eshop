<!--- BEGIN HEADER -->
# Changelog

All notable changes to this project will be documented in this file.
<!--- END HEADER -->

## [2.1.331](https://github.com/liquiddesign/eshop/compare/v2.1.330...v2.1.331) (2024-10-07)

### Bug Fixes

* Add shop-specific filtering to merchant pricelist retrieval. ([d26d9d](https://github.com/liquiddesign/eshop/commit/d26d9dcb043ceb347eaf9ed4ec1af1fab8ff5519))


---

## [2.1.330](https://github.com/liquiddesign/eshop/compare/v2.1.329...v2.1.330) (2024-10-07)

### Bug Fixes

* Discount query by adding joins and updating conditions ([2a8d0e](https://github.com/liquiddesign/eshop/commit/2a8d0ecc09e9221dfab825ac36952349c1c0db9a))


---

## [2.1.329](https://github.com/liquiddesign/eshop/compare/v2.1.328...v2.1.329) (2024-10-02)

### Features

* Add product codes and identifiers to cart item representation in OrderRepository ([7f6b05](https://github.com/liquiddesign/eshop/commit/7f6b05c80441be602d55744738a3cd9d44b779a7))
* Add getNextStep method to determine the next checkout step based on the current step and cart ID. ([11b183](https://github.com/liquiddesign/eshop/commit/11b1835895bc2918f3ace1d824e0d339b9b00f97))
* Add check for invalid step in CheckoutManager ([b85b0b](https://github.com/liquiddesign/eshop/commit/b85b0b14eab93e9ca49d0f528f21de5cd3244eec))


---

## [2.1.328](https://github.com/liquiddesign/eshop/compare/v2.1.327...v2.1.328) (2024-09-30)


---

## [2.1.327](https://github.com/liquiddesign/eshop/compare/v2.1.326...v2.1.327) (2024-09-26)

### Features

* Add PackageItem interface and implement in PackageItem and RelatedPackageItem ([7e2d3a](https://github.com/liquiddesign/eshop/commit/7e2d3ae96c4dacf977c716aecec02165c7266b9d))


---

## [2.1.326](https://github.com/liquiddesign/eshop/compare/v2.1.325...v2.1.326) (2024-09-24)

### Features

* Add link to order items in package item details ([4e30dc](https://github.com/liquiddesign/eshop/commit/4e30dc01bcc7fd0b842e7a850badf3d52d8c88cc))


---

## [2.1.325](https://github.com/liquiddesign/eshop/compare/v2.1.324...v2.1.325) (2024-09-24)

### Features

* Add edit and link icons for order items in order detail ([cfb2ce](https://github.com/liquiddesign/eshop/commit/cfb2ce8b7ef5df65363f03d0a9034019e2652465))


---

## [2.1.324](https://github.com/liquiddesign/eshop/compare/v2.1.323...v2.1.324) (2024-09-23)

### Features

* Add order to payment result and response in Comgate integration ([a588b9](https://github.com/liquiddesign/eshop/commit/a588b9a8df994a6860b424ff0e68c33b14a65165))


---

## [2.1.323](https://github.com/liquiddesign/eshop/compare/v2.1.322...v2.1.323) (2024-09-23)

### Bug Fixes


##### Product

* Missing getter ([b591ab](https://github.com/liquiddesign/eshop/commit/b591abe8fc3d5ec761fb2d79cf6ab3577503a121))


---

## [2.1.322](https://github.com/liquiddesign/eshop/compare/v2.1.321...v2.1.322) (2024-09-23)

### Builds

* Add ignoreErrors for missingType.generics and update PackageItemRepository docblock ([4bf514](https://github.com/liquiddesign/eshop/commit/4bf514a6239a4e0c455e52f5aa5e677b9be1c3d4))


---

## [2.1.320](https://github.com/liquiddesign/eshop/compare/v2.1.319...v2.1.320) (2024-09-11)

### Features

* Add GetPrimaryFileName action for product image handling ([557fd4](https://github.com/liquiddesign/eshop/commit/557fd425637a3979af621534cca3136ca1b88f23))


---

## [2.1.319](https://github.com/liquiddesign/eshop/compare/v2.1.318...v2.1.319) (2024-09-10)

### Features

* Add OpenGraph image support to category and product forms ([100e94](https://github.com/liquiddesign/eshop/commit/100e942fbd3f422169877dec4b1144983fb98d3c))


---

## [2.1.318](https://github.com/liquiddesign/eshop/compare/v2.1.317...v2.1.318) (2024-09-10)

### Features

* Add OrderEditService to phpstan config and improve addProduct method ([18198d](https://github.com/liquiddesign/eshop/commit/18198ddf0651c2f630bec0d7e0f8817ed187aab9))


---

## [2.1.317](https://github.com/liquiddesign/eshop/compare/v2.1.316...v2.1.317) (2024-09-09)

### Features

* Add flash messages and internal ribbons relation to order ([1df077](https://github.com/liquiddesign/eshop/commit/1df0777067c889c46ff6f5012be48e7ff813a933))

### Builds

* Fix actions ([c4b7fa](https://github.com/liquiddesign/eshop/commit/c4b7fa5e19e48db852d9a1099b71f991c0b10053))
* Better phpstan ([aa5bc6](https://github.com/liquiddesign/eshop/commit/aa5bc61399fd659daa91c83eea7bb2c5bca96fe1))


---

## [2.1.316](https://github.com/liquiddesign/eshop/compare/v2.1.315...v2.1.316) (2024-09-06)


---

## [2.1.315](https://github.com/liquiddesign/eshop/compare/v2.1.314...v2.1.315) (2024-09-05)

### Features

* Enhance product filter functionality by integrating ProductsCacheGetterService. Update attribute counts logic to use cached data for improved performance and accuracy. Adjust placeholders in filter forms based on count values and clean up commented code for clarity. ([cfb963](https://github.com/liquiddesign/eshop/commit/cfb9634f4d2283781874783e4a1828d607f87d0e))
* Better attribute counts ([fbb5b6](https://github.com/liquiddesign/eshop/commit/fbb5b64aa88ac738a810bad61e7dbd83462be5c6))

### Bug Fixes

* Product retrieval and error handling in CategoryRepository ([5f4e85](https://github.com/liquiddesign/eshop/commit/5f4e85bbcd421b077e5d7f7dafd4889699e7bc24))
* Change 'content' to 'description' in ProductExporter export properties ([6536ac](https://github.com/liquiddesign/eshop/commit/6536acc7c0b068fa37f5c6b9f17d3035f1ea8ed0))


---

## [2.1.314](https://github.com/liquiddesign/eshop/compare/v2.1.313...v2.1.314) (2024-09-05)

### Bug Fixes

* Product retrieval and error handling in CategoryRepository ([b42fd2](https://github.com/liquiddesign/eshop/commit/b42fd223b8893c8614105eb3df96c34a22b88a34))


---

## [2.1.313](https://github.com/liquiddesign/eshop/compare/v2.1.312...v2.1.313) (2024-09-05)

### Bug Fixes

* Change 'content' to 'description' in ProductExporter export properties ([ba5341](https://github.com/liquiddesign/eshop/commit/ba5341fe3d64a0762d5d27be8555de8b608ca1c4))
* Add shops filter select to ribbon grids and disable auto-select ([c6a6c3](https://github.com/liquiddesign/eshop/commit/c6a6c392b594286af171b19dbccd2ce1643b0021))


---

## [2.1.312](https://github.com/liquiddesign/eshop/compare/v2.1.311...v2.1.312) (2024-09-02)

### Features

* Increase max length for product grid fields to 80 characters ([aaa0b9](https://github.com/liquiddesign/eshop/commit/aaa0b90eb3f01fcfc003363e999bcdd91326da3b))


---

## [2.1.311](https://github.com/liquiddesign/eshop/compare/v2.1.310...v2.1.311) (2024-09-02)

### Features

* Update product import flash messages and improve error handling ([bab59a](https://github.com/liquiddesign/eshop/commit/bab59a23e86597bac14288f89c7a33f802836c9f))

### Builds

* Add git push command to release-patch script ([b52bdc](https://github.com/liquiddesign/eshop/commit/b52bdc6ee39edc4952b0e6d3627e0ec2bdf121a2))


---

## [2.1.310](https://github.com/liquiddesign/eshop/compare/v2.1.309...v2.1.310) (2024-09-02)

### Features

* Extract additional columns into separate method ([a19c39](https://github.com/liquiddesign/eshop/commit/a19c39ee52b32b1f46d71196ea381a4560d3e048))

### Builds

* Update release script to push tags with patches ([82bba8](https://github.com/liquiddesign/eshop/commit/82bba8c8b694a4569340e8d642dc4898b406246e))


---

## [2.1.309](https://github.com/liquiddesign/eshop/compare/v2.1.308...v2.1.309) (2024-08-31)

### Bug Fixes

* Prefix attribute keys with 'att_', consistently process attribute PKs ([5f8f6f](https://github.com/liquiddesign/eshop/commit/5f8f6fa34447866103dab4849e7db639f205ab46))


---

## [2.1.308](https://github.com/liquiddesign/eshop/compare/v2.1.307...v2.1.308) (2024-08-30)

### Features

* Truncate product page description in grid to 30 characters ([de776d](https://github.com/liquiddesign/eshop/commit/de776de8fba31a5c02d8bbeb80247c0bab61aadf))


---

## [2.1.307](https://github.com/liquiddesign/eshop/compare/v2.1.306...v2.1.307) (2024-08-30)

### Features

* Refactor product grid and exporter for improved performance ([f0d891](https://github.com/liquiddesign/eshop/commit/f0d8914dbfba1f69210e7cccbc87704bee60d3e9))

### Builds

* Add release-patch script for PowerShell ([4fe069](https://github.com/liquiddesign/eshop/commit/4fe0696168fc4f9f6b800295c6cbb36484d21ed2))


---

## [2.1.306](https://github.com/liquiddesign/eshop/compare/v2.1.305...v2.1.306) (2024-08-30)

### Bug Fixes

* Product export to use array values for filtered UUIDs ([c8e612](https://github.com/liquiddesign/eshop/commit/c8e612c3285921974334522550e385d32533d9b0))


---

## [2.1.305](https://github.com/liquiddesign/eshop/compare/v2.1.304...v2.1.305) (2024-08-29)

### Bug Fixes

* Ensure export and import columns are returned as arrays, even if configuration is missing ([06f0a0](https://github.com/liquiddesign/eshop/commit/06f0a0d172647ffdf4eeff7093ab46de43649a4c))


---

## [2.1.304](https://github.com/liquiddesign/eshop/compare/v2.1.303...v2.1.304) (2024-08-28)

### Features

* Refactor ProductPresenter methods to use dedicated getters for import and export columns. ([9cb1db](https://github.com/liquiddesign/eshop/commit/9cb1db87ea0f96f843b86fca29fcd31af1872cd0))


---

## [2.1.303](https://github.com/liquiddesign/eshop/compare/v2.1.302...v2.1.303) (2024-08-26)

### Features

* Add conditional rendering for breadcrumb and product rich snippets ([859a3a](https://github.com/liquiddesign/eshop/commit/859a3a027f640365cee4555430ea58603955c69b))


---

## [2.1.302](https://github.com/liquiddesign/eshop/compare/v2.1.301...v2.1.302) (2024-08-26)

### Features

* Add category filter to photo grid in admin panel ([ebbd8d](https://github.com/liquiddesign/eshop/commit/ebbd8d9fb23f79d6b0758647ca2436531ff1c35c))

##### Visibility List Repository

* Add ShopperUser dependency and refactor category filtering ([cc60fe](https://github.com/liquiddesign/eshop/commit/cc60fe99ee2e5ce82af9ebe21415c1ee9c1896a5))


---

## [2.1.301](https://github.com/liquiddesign/eshop/compare/v2.1.300...v2.1.301) (2024-08-23)

### Features

* Add method to get non-hidden files collection for product ([80a245](https://github.com/liquiddesign/eshop/commit/80a24540300d5c511c330893cd080c2ce8b32ccd))


---

## [2.1.300](https://github.com/liquiddesign/eshop/compare/v2.1.299...v2.1.300) (2024-08-23)

### Features

* Refactor attribute filtering logic for numeric sliders and ranges ([2e5161](https://github.com/liquiddesign/eshop/commit/2e51616d0aeff4d30f7a2b801f41b384b47140f5))


---

## [2.1.299](https://github.com/liquiddesign/eshop/compare/v2.1.298...v2.1.299) (2024-08-22)

### Features

* Refactor numeric slider attribute filtering to handle empty values ([269706](https://github.com/liquiddesign/eshop/commit/269706eb1feb90b2dceeebf95fbfb58793572e74))


---

## [2.1.298](https://github.com/liquiddesign/eshop/compare/v2.1.297...v2.1.298) (2024-08-22)

### Features

* Update release script and fix Heureka export ([a9f98e](https://github.com/liquiddesign/eshop/commit/a9f98ef7e25bf688ae614969a6b1a56d41ae3c5e))


---

## [2.1.293](https://github.com/liquiddesign/eshop/compare/v2.1.292...v2.1.293) (2024-08-14)

### Bug Fixes

* Enhance pricelist validation with discount joins and grouping ([141570](https://github.com/liquiddesign/eshop/commit/1415708cca2346f6319992a5913ce9bdb3110902))


---

## [2.1.292](https://github.com/liquiddesign/eshop/compare/v2.1.291...v2.1.292) (2024-08-13)

### Features

* Add notInStockCallback parameter to syncDisplayAmounts method ([16a1ef](https://github.com/liquiddesign/eshop/commit/16a1ef19a555e275f9886154b5bf6a59ae6b3e99))
* Update supplier product grid sorting and add creation date column ([f570e7](https://github.com/liquiddesign/eshop/commit/f570e75b36f54f97af9cf80670f0bedfaaeb8577))


---

## [2.1.291](https://github.com/liquiddesign/eshop/compare/v2.1.290...v2.1.291) (2024-08-12)

### Features

* Refactor discount and pricelist relationship to many-to-many ([4b8eb7](https://github.com/liquiddesign/eshop/commit/4b8eb7a66cbcbe457fa6fa73ec1b8d22e5029f5e))
* Add many-to-many relationship between Pricelist and Discount ([444a36](https://github.com/liquiddesign/eshop/commit/444a362541b294fbb8fae310e902bc431d406a90), [5b0695](https://github.com/liquiddesign/eshop/commit/5b069516fcedf2967fa760688350708d836e585a))


---

## [2.1.290](https://github.com/liquiddesign/eshop/compare/v2.1.289...v2.1.290) (2024-08-12)

### Features

* Add product grid item count callback and pre-filter form ([a8a388](https://github.com/liquiddesign/eshop/commit/a8a3888d769062fe73f3acf576aca626511e7e9f))


---

## [2.1.289](https://github.com/liquiddesign/eshop/compare/v2.1.288...v2.1.289) (2024-08-09)

### Features

* Add phpstan8 check and update MerchantPresenter ([7dcdfe](https://github.com/liquiddesign/eshop/commit/7dcdfeb74af39a90c742e84912d4120a8625a5c7))


---

## [2.1.288](https://github.com/liquiddesign/eshop/compare/v2.1.287...v2.1.288) (2024-08-09)

### Features

* Add fullname filter to accounts grid in CustomerPresenter ([afef49](https://github.com/liquiddesign/eshop/commit/afef4939bf2e327208baeb102e7a894205daaab4))


---

## [2.1.287](https://github.com/liquiddesign/eshop/compare/v2.1.286...v2.1.287) (2024-08-08)

### Features

* New function setDraftsCollection ([b24392](https://github.com/liquiddesign/eshop/commit/b2439272222f69c41864674c26d57bcb7d3fd68e))
* Add deletedTs ([105093](https://github.com/liquiddesign/eshop/commit/105093329cb44beb5a64529e63c29055fea48e35))


---

## [2.1.286](https://github.com/liquiddesign/eshop/compare/v2.1.285...v2.1.286) (2024-08-08)

### Features

* Add lastInStockTs ([091f46](https://github.com/liquiddesign/eshop/commit/091f46a609da9e9c6f4592f75e6e80b5160c59c9))


---

## [2.1.285](https://github.com/liquiddesign/eshop/compare/v2.1.284...v2.1.285) (2024-08-07)

### Features

* Refactor product getters and simplify display amount logic ([e77bd4](https://github.com/liquiddesign/eshop/commit/e77bd46dc5f0d2406b493069b825eea3a69137ea))
* Add deprecation notices and optimize getFullCode method ([70db9c](https://github.com/liquiddesign/eshop/commit/70db9cd6160e4e6818cef042b77dac86ba475774))
* Update ExportPresenter with improved type safety and error handling ([a90084](https://github.com/liquiddesign/eshop/commit/a9008410faa75efdd5376a3754b729c67b8d5e64))

### Bug Fixes

* Potential null reference in Order::isFirstOrder() method ([ec76ad](https://github.com/liquiddesign/eshop/commit/ec76ad640e0205d9ff698f71d6632c9760f8dc72))

### Styles

* Exclude ExportPresenter from static analysis and disable variable naming rule ([da8629](https://github.com/liquiddesign/eshop/commit/da86290b24d2bf854c9986446e1836b61cbb8f9b))


---

## [2.1.284](https://github.com/liquiddesign/eshop/compare/v2.1.283...v2.1.284) (2024-08-05)

### Features

* Add bulk edit button and custom fields to producer form ([68489d](https://github.com/liquiddesign/eshop/commit/68489d9aa3578d4ca20b9aa207258f471688fb54))


---

## [2.1.283](https://github.com/liquiddesign/eshop/compare/v2.1.282...v2.1.283) (2024-08-05)

### Features

* Refactor CategoryPresenter and ProductGridFactory for better extensibility ([344449](https://github.com/liquiddesign/eshop/commit/34444949da3bb9fceec14073291db475eb45d4a9))

### Bug Fixes

* Add getDeliveries() method to Order class and update usage ([d42d47](https://github.com/liquiddesign/eshop/commit/d42d4758b55008c049ad61b0a897386113de0cf4))

### Styles

* Fix ([081233](https://github.com/liquiddesign/eshop/commit/081233fa3cfcaeab3fe6bfc9cd411499b21b7872))


---

## [2.1.282](https://github.com/liquiddesign/eshop/compare/v2.1.281...v2.1.282) (2024-08-02)

### Bug Fixes

* Add IFNULL check for priceVat in product price selection ([91dce2](https://github.com/liquiddesign/eshop/commit/91dce2ef85fdc672b08242bed0f95a631824b33c))


---

## [2.1.281](https://github.com/liquiddesign/eshop/compare/v2.1.280...v2.1.281) (2024-08-02)

### Features

* Update merchant visibility lists and related functionality ([7a2e06](https://github.com/liquiddesign/eshop/commit/7a2e065e827f890935165bfc171adb0cc09d6e80))


---

## [2.1.280](https://github.com/liquiddesign/eshop/compare/v2.1.279...v2.1.280) (2024-08-01)

### Features

* Add maximum order price fields for customers ([86ec99](https://github.com/liquiddesign/eshop/commit/86ec9968547baf5e65c63e4f527f15a5f2517236))
* Add supplier categories filter to attribute mapping grid ([2b9bbb](https://github.com/liquiddesign/eshop/commit/2b9bbbbb2ff9b59874828342f0c26a8c030d30e4))


---

## [2.1.279](https://github.com/liquiddesign/eshop/compare/v2.1.278...v2.1.279) (2024-08-01)

### Features

* Refactor order completion and cancellation handling ([0b1352](https://github.com/liquiddesign/eshop/commit/0b13529584ab4b7d6b2cc5e4535a97cdad0b2c59))


---

## [2.1.278](https://github.com/liquiddesign/eshop/compare/v2.1.277...v2.1.278) (2024-07-31)

### ⚠ BREAKING CHANGES

* Update Comgate integration and bump package version ([7cf737](https://github.com/liquiddesign/eshop/commit/7cf7375d086198bdde9745f7b326be6f2a9f9f01))


---

## [2.1.277](https://github.com/liquiddesign/eshop/compare/v2.1.276...v2.1.277) (2024-07-30)

### Features

* Optimize supplier attribute value assignment and improve performance ([40da51](https://github.com/liquiddesign/eshop/commit/40da518be73749f415007b8938c48a91a1fc279b))


---

## [2.1.276](https://github.com/liquiddesign/eshop/compare/v2.1.275...v2.1.276) (2024-07-30)

### Features

* Add filterType column and update attribute creation ([f6bd51](https://github.com/liquiddesign/eshop/commit/f6bd51c118a023ba1474c226cc40ec6418016e12))
* Update SupplierMappingPresenter with attribute category info ([2056aa](https://github.com/liquiddesign/eshop/commit/2056aad11e6188757e8e333d43826532ee6f2a64))
* Update bulk mapping functionality and refactor grid creation ([009af0](https://github.com/liquiddesign/eshop/commit/009af05866368b09f6278529d615b2941e0a19d6))

### Bug Fixes

* Supplier filter in SupplierMappingPresenter ([57089c](https://github.com/liquiddesign/eshop/commit/57089c62abc8a69ded497500259df23f94f57cb3))

### Styles

* Fix ([d785ca](https://github.com/liquiddesign/eshop/commit/d785ca96898145468dd3bbdea43fee2c82188871))


---

## [2.1.275](https://github.com/liquiddesign/eshop/compare/v2.1.274...v2.1.275) (2024-07-29)

### Features

* Add free delivery check for Google and Heureka exports ([7a9e61](https://github.com/liquiddesign/eshop/commit/7a9e6198d585d95db70bd511b4705899dca8ac22))

### Styles

* Fix ([ebf893](https://github.com/liquiddesign/eshop/commit/ebf8930259620370c660c452d4e60d534634c780))


---

## [2.1.274](https://github.com/liquiddesign/eshop/compare/v2.1.273...v2.1.274) (2024-07-24)


---

## [2.1.272](https://github.com/liquiddesign/eshop/compare/v2.1.271...v2.1.272) (2024-07-10)


---

## [2.1.271](https://github.com/liquiddesign/eshop/compare/v2.1.270...v2.1.271) (2024-07-10)

### Features

* Add methods to calculate checkout discount prices ([a76edb](https://github.com/liquiddesign/eshop/commit/a76edb6af9ca14f08b4a7a727a06fab3c47683ac))


---

## [2.1.270](https://github.com/liquiddesign/eshop/compare/v2.1.269...v2.1.270) (2024-07-10)

### Bug Fixes

* Update order price calculation to use non-VAT sums ([5b3194](https://github.com/liquiddesign/eshop/commit/5b31947c5e92da914173ebddfc036d0ca99ac52e))


---

## [2.1.269](https://github.com/liquiddesign/eshop/compare/v2.1.268...v2.1.269) (2024-07-10)

### Features

* Update price formatting to use priceSecondary filter ([fba152](https://github.com/liquiddesign/eshop/commit/fba15260d5af41de67a537fe926a20636ba70436))


---

## [2.1.268](https://github.com/liquiddesign/eshop/compare/v2.1.267...v2.1.268) (2024-07-09)

### Features

* Add shop support to newsletter user operations ([9d898a](https://github.com/liquiddesign/eshop/commit/9d898a1e7dc7e27749d4d914a5132ffd9dd5b857))


---

## [2.1.267](https://github.com/liquiddesign/eshop/compare/v2.1.266...v2.1.267) (2024-07-09)

### Features

* Add shops filter and shop association to newsletter users ([f54724](https://github.com/liquiddesign/eshop/commit/f54724c1eb09738f4c7952cbc38c8e111abc5a1b))


---

## [2.1.266](https://github.com/liquiddesign/eshop/compare/v2.1.265...v2.1.266) (2024-07-08)

### Features

* Add debug logging for invalid Comgate payment requests ([d75da2](https://github.com/liquiddesign/eshop/commit/d75da260494e991310e34a0ffb91b84a72017d55))


---

## [2.1.265](https://github.com/liquiddesign/eshop/compare/v2.1.264...v2.1.265) (2024-07-08)

### Bug Fixes

* Remove backLink parameter from Administrator detail link ([7b0469](https://github.com/liquiddesign/eshop/commit/7b0469deabd7806f763705c5883824442e56c843))


---

## [2.1.264](https://github.com/liquiddesign/eshop/compare/v2.1.263...v2.1.264) (2024-07-08)

### Bug Fixes

* Update canceled order query condition in OrderRepository ([83cbae](https://github.com/liquiddesign/eshop/commit/83cbaea6fb4ae678c650075f3824a4d6bbd6eae5))


---

## [2.1.263](https://github.com/liquiddesign/eshop/compare/v2.1.262...v2.1.263) (2024-07-03)

### Features

* Add getCheckoutPriceVatBefore method to CheckoutManager ([775131](https://github.com/liquiddesign/eshop/commit/775131563246ca31a251217037e540d8b45ac128))


---

## [2.1.262](https://github.com/liquiddesign/eshop/compare/v2.1.261...v2.1.262) (2024-07-03)

### Features

* Update price rounding and handle missing related products ([4be556](https://github.com/liquiddesign/eshop/commit/4be5561095c4d65e9c410b1f3457bd0b00afedf3))


---

## [2.1.261](https://github.com/liquiddesign/eshop/compare/v2.1.260...v2.1.261) (2024-07-02)

### Bug Fixes

* Update order editing with transaction and error handling ([a08e57](https://github.com/liquiddesign/eshop/commit/a08e57dadf38d3579e16c7eadaca667da1c1bd1d))


---

## [2.1.260](https://github.com/liquiddesign/eshop/compare/v2.1.259...v2.1.260) (2024-07-01)

### Features

* Add error handling for custom order total price calculation in Comgate service ([20e836](https://github.com/liquiddesign/eshop/commit/20e836f2c927d82795cac280a0ea52decc0038f2))
* Remove unused CSV reader methods and imports ([59df6a](https://github.com/liquiddesign/eshop/commit/59df6a382d7d47be27387ab211965c4433f91d2a))
* Update product name retrieval to use getName() method ([a61a8d](https://github.com/liquiddesign/eshop/commit/a61a8d40ec65b11b67603b9fd932187583fdedd1))


---

## [2.1.259](https://github.com/liquiddesign/eshop/compare/v2.1.258...v2.1.259) (2024-06-26)

### Features

* Add methods to check and unregister email in NewsletterUserRepository ([19daaa](https://github.com/liquiddesign/eshop/commit/19daaa54bc7f4a52d5fc05dff8a1624a204d4bc2))


---

## [2.1.258](https://github.com/liquiddesign/eshop/compare/v2.1.257...v2.1.258) (2024-06-26)

### Bug Fixes

* Fix account creation logic and error handling in AddressesForm ([a9676f](https://github.com/liquiddesign/eshop/commit/a9676f5dafa55cc72915340e7102871eb743f97a))


---

## [2.1.257](https://github.com/liquiddesign/eshop/compare/v2.1.256...v2.1.257) (2024-06-26)

### Features

* Add PaymentResults relation to Order entity ([0b4a97](https://github.com/liquiddesign/eshop/commit/0b4a97d7917b64b1ea7fb8bcfaa986f5b37a49a8))


---

## [2.1.256](https://github.com/liquiddesign/eshop/compare/v2.1.255...v2.1.256) (2024-06-26)

### Features

* Add performance optimizations to ProductsCacheWarmUpService ([eba359](https://github.com/liquiddesign/eshop/commit/eba359bd4178376e08d1c300ad3df4ee41a346ba))


---

## [2.1.255](https://github.com/liquiddesign/eshop/compare/v2.1.254...v2.1.255) (2024-06-26)

### Features

* Update ProductsCacheWarmUpService to use current cache index ([e5042a](https://github.com/liquiddesign/eshop/commit/e5042a5bb364776e187e416152b52a7030f60644))


---

## [2.1.254](https://github.com/liquiddesign/eshop/compare/v2.1.253...v2.1.254) (2024-06-24)

### Bug Fixes


##### Products Cache

* Interface ([d85ecd](https://github.com/liquiddesign/eshop/commit/d85ecd1a18e05805e850451ce7fab91d6ac13afe))


---

## [2.1.253](https://github.com/liquiddesign/eshop/compare/v2.1.252...v2.1.253) (2024-06-24)

### Bug Fixes


##### Checkout Manager

* Save company name correctly ([8fb631](https://github.com/liquiddesign/eshop/commit/8fb631487d35d86760ed0384dbcb1f3e9fecf01d))

##### Products Cache

* New system to change only changed rows ([1adde5](https://github.com/liquiddesign/eshop/commit/1adde502d753293767291c2973cffdad3650d37e))


---

## [2.1.252](https://github.com/liquiddesign/eshop/compare/v2.1.251...v2.1.252) (2024-06-21)

### Bug Fixes


##### Customer Form

* Nullable ([076629](https://github.com/liquiddesign/eshop/commit/076629cd6978acdb23e520036886663ee338a9ae))


---

## [2.1.251](https://github.com/liquiddesign/eshop/compare/v2.1.250...v2.1.251) (2024-06-21)

### Features

* Revert cache indexing ([0a42da](https://github.com/liquiddesign/eshop/commit/0a42da77b43a14abae4084acb058e9601306ea19))


---

## [2.1.250](https://github.com/liquiddesign/eshop/compare/v2.1.249...v2.1.250) (2024-06-21)

### Bug Fixes


##### Product Importer

* Fetch pages separately ([97c96d](https://github.com/liquiddesign/eshop/commit/97c96d78a524fcb8d76d4668177b46a8980f60cf))


---

## [2.1.249](https://github.com/liquiddesign/eshop/compare/v2.1.248...v2.1.249) (2024-06-17)

### Bug Fixes

* Merge ([eef164](https://github.com/liquiddesign/eshop/commit/eef1645c85791e30b2a07ea4a005bc9eb1245afa))


---

## [2.1.248](https://github.com/liquiddesign/eshop/compare/v2.1.247...v2.1.248) (2024-06-17)

### Bug Fixes

* Cache ([2fff9b](https://github.com/liquiddesign/eshop/commit/2fff9b263c9a28006c306fc0e23980ede6a8bf25))


---

## [2.1.247](https://github.com/liquiddesign/eshop/compare/v2.1.246...v2.1.247) (2024-06-17)

### Bug Fixes

* Merge ([b60e34](https://github.com/liquiddesign/eshop/commit/b60e344e3588be4d2fb13be2fb1c2fa1146139c0))


---

## [2.1.246](https://github.com/liquiddesign/eshop/compare/v2.1.245...v2.1.246) (2024-06-17)

### Features


##### Products Cache Update Service

* Insert performance ([784d75](https://github.com/liquiddesign/eshop/commit/784d758457e9d6839d493ee2beaa3ee4d01acf06))


---

## [2.1.245](https://github.com/liquiddesign/eshop/compare/v2.1.244...v2.1.245) (2024-06-17)

### Features


##### Products Cache Update Service

* Insert performance ([4597cd](https://github.com/liquiddesign/eshop/commit/4597cd9f854b5dd38986b83e6fd27e3e8a676841))


---

## [2.1.244](https://github.com/liquiddesign/eshop/compare/v2.1.243...v2.1.244) (2024-06-17)

### Features


##### Products Cache Update Service

* Insert performance ([06fb46](https://github.com/liquiddesign/eshop/commit/06fb466cd21f2cf81e2b7c9ccc47f78de4f06b96))


---

## [2.1.243](https://github.com/liquiddesign/eshop/compare/v2.1.242...v2.1.243) (2024-06-14)

### Bug Fixes


##### Products Merger

* Associative array ([d37c75](https://github.com/liquiddesign/eshop/commit/d37c75c534d6cd704b3b7df187f6dd098e730d43))

### Styles

* Fix ([f59786](https://github.com/liquiddesign/eshop/commit/f597866a84f0adfbd9496739497669a1f2d05d39))


---

## [2.1.242](https://github.com/liquiddesign/eshop/compare/v2.1.241...v2.1.242) (2024-06-13)

### Features


##### Products Cache Update Service

* UpdateCustomerVisibilitiesAndPrices - add $transaction ([b0a51b](https://github.com/liquiddesign/eshop/commit/b0a51bf46f036db078e7339d3247e6b6ff479f61))

### Bug Fixes


##### Products Merger

* Associative array ([49ed46](https://github.com/liquiddesign/eshop/commit/49ed462deb2ab12f3a84903b8785eb25dba10491))


---

## [2.1.241](https://github.com/liquiddesign/eshop/compare/v2.1.240...v2.1.241) (2024-06-10)

### Features


##### Admin Customer

* Add sessionIgnoreLoad to link ([9cb897](https://github.com/liquiddesign/eshop/commit/9cb8971ddc3891870dec4446e6605e4ce45b354d))

### Bug Fixes


##### I Register Form Factory

* AccountType values ([45829a](https://github.com/liquiddesign/eshop/commit/45829ae80c9e9d50110226e525760179b1f33fd3))


---

## [2.1.240](https://github.com/liquiddesign/eshop/compare/v2.1.239...v2.1.240) (2024-06-05)

### Bug Fixes


##### Pruchase

* Don't update email ([7ff4a6](https://github.com/liquiddesign/eshop/commit/7ff4a6581247dc99e856dda0092c24fe9c8bbf4f))


---

## [2.1.239](https://github.com/liquiddesign/eshop/compare/v2.1.238...v2.1.239) (2024-06-05)

### Bug Fixes


##### Order

* Template ([b7e9ad](https://github.com/liquiddesign/eshop/commit/b7e9ad1e94432e4bce0508f3442b17909a99854b))


---

## [2.1.238](https://github.com/liquiddesign/eshop/compare/v2.1.237...v2.1.238) (2024-06-05)

### Bug Fixes


##### Cart

* Constraint fix ([bac940](https://github.com/liquiddesign/eshop/commit/bac940c94d8dcaa788633785cdf5bc7461e80032))

##### Order

* Template show only Purchase info ([bca7ae](https://github.com/liquiddesign/eshop/commit/bca7aee857d28b8af3bc61eb3b5ac30f938dd3f6))


---

## [2.1.237](https://github.com/liquiddesign/eshop/compare/v2.1.236...v2.1.237) (2024-06-03)

### ⚠ BREAKING CHANGES


##### Order

* Create separate address rows when create customer in order ([af969a](https://github.com/liquiddesign/eshop/commit/af969ab289dd7aba28d6d30b47bfe51320ff79a0))


---

## [2.1.236](https://github.com/liquiddesign/eshop/compare/v2.1.235...v2.1.236) (2024-05-31)

### Features


##### Category

* Better cache ([26ef27](https://github.com/liquiddesign/eshop/commit/26ef274391500c4475df2a507470786ad727c613))


---

## [2.1.235](https://github.com/liquiddesign/eshop/compare/v2.1.234...v2.1.235) (2024-05-29)

### Features


##### Customer

* Show IC ([c0bab2](https://github.com/liquiddesign/eshop/commit/c0bab25469e4d47397e2981f2cc7efc054bc0562))

### Bug Fixes


##### Profile Form

* Validation, types ([599cfe](https://github.com/liquiddesign/eshop/commit/599cfe9e27bd85f283bee435ac1e89a60c4ed411))


---

## [2.1.234](https://github.com/liquiddesign/eshop/compare/v2.1.233...v2.1.234) (2024-05-29)

### Features


##### Customer

* Favourite products bulk edit ([ca9a72](https://github.com/liquiddesign/eshop/commit/ca9a72e5a18c8a22128d424a04666ae1c25f51b9))


---

## [2.1.233](https://github.com/liquiddesign/eshop/compare/v2.1.232...v2.1.233) (2024-05-29)

### Features


##### Customer

* Favourite products label change ([dda5c8](https://github.com/liquiddesign/eshop/commit/dda5c8fc4c9e8f45c11e921336e144b2d2ec72bd))


---

## [2.1.232](https://github.com/liquiddesign/eshop/compare/v2.1.231...v2.1.232) (2024-05-28)

### Features


##### Customer

* Favourite products ([e543d5](https://github.com/liquiddesign/eshop/commit/e543d5e420fdc9ad1ebd5bfb33b0ee774f3d7e77))


---

## [2.1.231](https://github.com/liquiddesign/eshop/compare/v2.1.230...v2.1.231) (2024-05-28)

### Features


##### Admin

* Add bulk email sending to Accounts ([d32190](https://github.com/liquiddesign/eshop/commit/d32190eaa7f033dd94e803879dde28caad8b242f))
* Add bulk edit to Groups ([1261f8](https://github.com/liquiddesign/eshop/commit/1261f8486575fd1c6bc3e9d955581907590f653e))

### Bug Fixes


##### Admin

* Isset ([05b649](https://github.com/liquiddesign/eshop/commit/05b6499c56e38adbc9f8b79c76fe9cb437287f5f))
* Login isAllowed ([e1231e](https://github.com/liquiddesign/eshop/commit/e1231ea00655b53c89adb8959a22bd3072ab10ce))

##### Front Product

* Query fix ([059715](https://github.com/liquiddesign/eshop/commit/059715978880929b443809dc0206fd5d1a3d8907))


---

## [2.1.230](https://github.com/liquiddesign/eshop/compare/v2.1.229...v2.1.230) (2024-05-27)

### Features

* Better error message ([5fe6c1](https://github.com/liquiddesign/eshop/commit/5fe6c1ed8a1288d3637d09347fe3ae4b4ec29cdf))

### Bug Fixes

* Bad mapping or relation between Merchant and Customer ([090546](https://github.com/liquiddesign/eshop/commit/090546ebdddb71b5a57b6ed021613607c87d26cf))

##### Product Filter

* Counts loading without cache ([2d8196](https://github.com/liquiddesign/eshop/commit/2d8196c92de5692de06eca6089400815d3e1961f))


---

## [2.1.229](https://github.com/liquiddesign/eshop/compare/v2.1.228...v2.1.229) (2024-05-25)

### Chores

* Update minimum version ([7e81a0](https://github.com/liquiddesign/eshop/commit/7e81a0f893b10d711179b8ffaa49eacf6e56a638))


---

## [2.1.228](https://github.com/liquiddesign/eshop/compare/v2.1.227...v2.1.228) (2024-05-24)

### Bug Fixes


##### Product Import

* Case insensitive ([8261cc](https://github.com/liquiddesign/eshop/commit/8261cce6240f5f4b346288f4049403d21f57825c))


---

## [2.1.227](https://github.com/liquiddesign/eshop/compare/v2.1.226...v2.1.227) (2024-05-24)

### Bug Fixes


##### Product Import

* Case insensitive ([d6da54](https://github.com/liquiddesign/eshop/commit/d6da549d88e0c380b3c9f173f7bf67cfc110bff4))


---

## [2.1.226](https://github.com/liquiddesign/eshop/compare/v2.1.225...v2.1.226) (2024-05-23)

### Bug Fixes


##### Addresses Form

* Check createAccount in shops ([ee4485](https://github.com/liquiddesign/eshop/commit/ee4485f52370affc4e2cf8fc53e4237c751dd1e5))

##### Purchase

* Save company name if fillProfile ([cf9fc5](https://github.com/liquiddesign/eshop/commit/cf9fc5b597add1daa10400122c2fe4c28d6cf943))


---

## [2.1.225](https://github.com/liquiddesign/eshop/compare/v2.1.224...v2.1.225) (2024-05-22)

### Bug Fixes


##### Product Filter

* Don't hide selected values ([75632c](https://github.com/liquiddesign/eshop/commit/75632c9b43897ea9dee3d5d40f5a8a782b4061f0))


---

## [2.1.224](https://github.com/liquiddesign/eshop/compare/v2.1.223...v2.1.224) (2024-05-22)

### Features

* Change onOrderReceived to not throw exception if message doesn't exist ([947c28](https://github.com/liquiddesign/eshop/commit/947c28b37fb0ad5f99a21244b33faf2489206d95))

##### Product Filter

* Don't show values with zero count ([f78756](https://github.com/liquiddesign/eshop/commit/f78756614ff3e401e6b00b527b6b8721ee0f3ffd))


---

## [2.1.223](https://github.com/liquiddesign/eshop/compare/v2.1.222...v2.1.223) (2024-05-22)

### Features


##### Addresses Form

* Add autocomplete hints ([52d451](https://github.com/liquiddesign/eshop/commit/52d45179c3428e3fffd66fc12740e27e2c6932b6))


---

## [2.1.222](https://github.com/liquiddesign/eshop/compare/v2.1.221...v2.1.222) (2024-05-21)

### Bug Fixes


##### Heureka

* Category tree bad loading ([6609a0](https://github.com/liquiddesign/eshop/commit/6609a04ebc0309844ffa87fd9cbcea769cc4dde1))


---

## [2.1.221](https://github.com/liquiddesign/eshop/compare/v2.1.220...v2.1.221) (2024-05-21)

### Bug Fixes

* GetSystemicAttributeValues empty array to where ([0794f4](https://github.com/liquiddesign/eshop/commit/0794f409fa77c05236c0d1d6f9f175f4066b5d26))


---

## [2.1.220](https://github.com/liquiddesign/eshop/compare/v2.1.219...v2.1.220) (2024-05-20)

### Features

* Add lastUpdateTs to PriceList ([61f440](https://github.com/liquiddesign/eshop/commit/61f44033837a636c33e2235d2ec84328af48554d))


---

## [2.1.219](https://github.com/liquiddesign/eshop/compare/v2.1.218...v2.1.219) (2024-05-16)

### Features

* New TemplateNamesService to get email templates names ([b48aa6](https://github.com/liquiddesign/eshop/commit/b48aa6f3bdbeeba3f7676f28f051649f0986cebc), [42262a](https://github.com/liquiddesign/eshop/commit/42262a4da30c359e8d71f103b6800e027bcc1320))


---

## [2.1.218](https://github.com/liquiddesign/eshop/compare/v2.1.217...v2.1.218) (2024-05-15)

### Bug Fixes


##### Products Cache

* Correctly filter PriceLists and VisibilityLists in warm up ([334e58](https://github.com/liquiddesign/eshop/commit/334e58cbe78a77144fa180d17aad446906fa859e))


---

## [2.1.217](https://github.com/liquiddesign/eshop/compare/v2.1.216...v2.1.217) (2024-05-13)

### Features


##### Admin Visibility List

* Add createdTs to items grid ([385f9e](https://github.com/liquiddesign/eshop/commit/385f9e57065a4c11daeaac636dc53a54ed0bf6e1))

### Styles

* Fix ([abd7e5](https://github.com/liquiddesign/eshop/commit/abd7e5e467b9d4cb6dc7dac6272cc68d43f58807))


---

## [2.1.216](https://github.com/liquiddesign/eshop/compare/v2.1.215...v2.1.216) (2024-05-09)

### Bug Fixes


##### Shopper User

* Default values ([24632a](https://github.com/liquiddesign/eshop/commit/24632a6102620cc71f0ef17e471e8475adcb5da1))


---

## [2.1.215](https://github.com/liquiddesign/eshop/compare/v2.1.214...v2.1.215) (2024-05-09)

### Features


##### Checkout

* Create account based on shop ([63d0af](https://github.com/liquiddesign/eshop/commit/63d0affe250434a601cbf371538850bf78219c5d))

##### Supplier

* Don't use transaction ([a144a5](https://github.com/liquiddesign/eshop/commit/a144a534f378499ab74c703a9ab6653588a8c4e1))


---

## [2.1.214](https://github.com/liquiddesign/eshop/compare/v2.1.213...v2.1.214) (2024-05-03)

### Features


##### Admin Product

* Filters ([f56317](https://github.com/liquiddesign/eshop/commit/f56317529ae3def28befc89fa517ae615df7a8cd))


---

## [2.1.213](https://github.com/liquiddesign/eshop/compare/v2.1.212...v2.1.213) (2024-05-03)

### Features


##### Admin Product

* Filters ([5d7a3f](https://github.com/liquiddesign/eshop/commit/5d7a3f26eab3e6167d4736202bbfe7b199c11c8d))


---

## [2.1.212](https://github.com/liquiddesign/eshop/compare/v2.1.211...v2.1.212) (2024-05-03)

### Bug Fixes

* Import ([1b292f](https://github.com/liquiddesign/eshop/commit/1b292f1ba4b1972937c46807a99d431dfd857f9c))


---

## [2.1.211](https://github.com/liquiddesign/eshop/compare/v2.1.210...v2.1.211) (2024-05-02)

### Bug Fixes

* Prices grid ([f8f3bb](https://github.com/liquiddesign/eshop/commit/f8f3bb5dad79e6138cb5c1a5c657761addf49b26))


---

## [2.1.210](https://github.com/liquiddesign/eshop/compare/v2.1.209...v2.1.210) (2024-05-02)

### Bug Fixes

* Various ([6744a9](https://github.com/liquiddesign/eshop/commit/6744a93e679d54a692d1ba0b988decf96018176f))


---

## [2.1.209](https://github.com/liquiddesign/eshop/compare/v2.1.208...v2.1.209) (2024-04-29)

### Features


##### Product

* Add createdTs ([bc4d5b](https://github.com/liquiddesign/eshop/commit/bc4d5b70d0b1b90b63c4d98833a62d596e3a05f6))


---

## [2.1.208](https://github.com/liquiddesign/eshop/compare/v2.1.207...v2.1.208) (2024-04-29)

### Bug Fixes


##### Products Cache

* Filter ranges correctly ([e1655e](https://github.com/liquiddesign/eshop/commit/e1655e56aaa10342c4027324a55319a565315bb1))

### Chores


##### Product List

* Hide dump ([ebf021](https://github.com/liquiddesign/eshop/commit/ebf021fb75b850269ca4311f92b38cb2ff357f20))


---

## [2.1.207](https://github.com/liquiddesign/eshop/compare/v2.1.206...v2.1.207) (2024-04-29)

### Bug Fixes


##### Admin Order

* Better error handling ([36d318](https://github.com/liquiddesign/eshop/commit/36d318993f591394c49c095d16cbbacd468688cc))


---

## [2.1.206](https://github.com/liquiddesign/eshop/compare/v2.1.205...v2.1.206) (2024-04-26)

### Features


##### Admin Product

* Filters ([83a7d8](https://github.com/liquiddesign/eshop/commit/83a7d8e6de1d8e7e9d89e31bb0b812081e27c883))


---

## [2.1.205](https://github.com/liquiddesign/eshop/compare/v2.1.204...v2.1.205) (2024-04-26)

### Features


##### Admin Customer

* Add onCustomerFormUniqueValidation ([2f5df5](https://github.com/liquiddesign/eshop/commit/2f5df5ac7fd17f4ad9bac483c264f0c229f3888c))

##### Admin Product

* Add callbacks ([06e392](https://github.com/liquiddesign/eshop/commit/06e392a8edaaf642a01285e229ab5ea716627bed))


---

## [2.1.204](https://github.com/liquiddesign/eshop/compare/v2.1.203...v2.1.204) (2024-04-23)

### Features


##### Product Form

* Move sections ([0d54f0](https://github.com/liquiddesign/eshop/commit/0d54f00f43ca3e8d1d6eed862bd97286464be9dd))

### Bug Fixes


##### Import

* Producer ([2c64ee](https://github.com/liquiddesign/eshop/commit/2c64eef0959075bcaf1f8f4a06ce8aea0551a127))


---

## [2.1.203](https://github.com/liquiddesign/eshop/compare/v2.1.202...v2.1.203) (2024-04-23)

### Features

* Add filters ([a26d76](https://github.com/liquiddesign/eshop/commit/a26d76b17f6f6d13839a545347977cee1d426afd))

##### Import

* Images ([640e5b](https://github.com/liquiddesign/eshop/commit/640e5ba24153cbd6a4f6fc6f8760fc3aca8de83d))

### Styles

* Fix ([36ab3a](https://github.com/liquiddesign/eshop/commit/36ab3a4252d63312e49af11d964d18ebe62dc7e5))


---

## [2.1.202](https://github.com/liquiddesign/eshop/compare/v2.1.201...v2.1.202) (2024-04-22)

### Bug Fixes

* Supplier stats ([86fe06](https://github.com/liquiddesign/eshop/commit/86fe0672b51ee338482077b54b84805220c96c92), [888e29](https://github.com/liquiddesign/eshop/commit/888e291250b7de695a4e23b15b0d1e90baeecb88))

##### Product Importer

* URL column null value in str_starts_with() ([a20738](https://github.com/liquiddesign/eshop/commit/a207385009822420b50a20a6029acd18ebd8214d))


---

## [2.1.201](https://github.com/liquiddesign/eshop/compare/v2.1.200...v2.1.201) (2024-04-18)

### Chores

* Remove debug ([8efcff](https://github.com/liquiddesign/eshop/commit/8efcff2e2e375863503c85d6a4737c7dd29ab421))


---

## [2.1.200](https://github.com/liquiddesign/eshop/compare/v2.1.199...v2.1.200) (2024-04-18)

### Bug Fixes


##### Import

* Generate page uuid based on shop ([6e7129](https://github.com/liquiddesign/eshop/commit/6e71294031c7ff88407fd45a95756a0630085d99))


---

## [2.1.199](https://github.com/liquiddesign/eshop/compare/v2.1.198...v2.1.199) (2024-04-17)

### Features


##### Price List

* Add masterProduct filter ([f22bbd](https://github.com/liquiddesign/eshop/commit/f22bbd986fcd144e9e6e9c93ff69607344fafe41))


---

## [2.1.198](https://github.com/liquiddesign/eshop/compare/v2.1.197...v2.1.198) (2024-04-17)

### Features


##### Product

* Add masterProduct filter ([9c1eea](https://github.com/liquiddesign/eshop/commit/9c1eea79c2cac208a62c639d86af8373d5e8b4d2))


---

## [2.1.197](https://github.com/liquiddesign/eshop/compare/v2.1.196...v2.1.197) (2024-04-17)

### Features


##### Dev

* Allow debug attribute to be zero to dump all queries ([4abe7e](https://github.com/liquiddesign/eshop/commit/4abe7ebc1aa8824c858ce8c0708ae6f7f45cf222))

##### Price List

* Add internal ribbons ([6f067c](https://github.com/liquiddesign/eshop/commit/6f067c5dc425031ff8feae873276214d46a33213))

##### Product

* Add transaction to product merging ([4acb43](https://github.com/liquiddesign/eshop/commit/4acb43e8a8329ffbb954c2508a315b7f7fc4381a))

### Bug Fixes


##### Ribbon

* Admin save ([604fa0](https://github.com/liquiddesign/eshop/commit/604fa0273a4b71e171955a536be79bbb11c0b00b))


---

## [2.1.196](https://github.com/liquiddesign/eshop/compare/v2.1.195...v2.1.196) (2024-04-16)

### Bug Fixes


##### Cart Item List

* Redirect if $cartItem is null ([ad264e](https://github.com/liquiddesign/eshop/commit/ad264e7543756e45be03abe47440f8c6f3da8878))


---

## [2.1.195](https://github.com/liquiddesign/eshop/compare/v2.1.194...v2.1.195) (2024-04-15)

### Features


##### Admin Product

* Add merge callback ([9ba8c6](https://github.com/liquiddesign/eshop/commit/9ba8c6a6edbf9d5782df5c8c63e52963e5a80c89))


---

## [2.1.194](https://github.com/liquiddesign/eshop/compare/v2.1.193...v2.1.194) (2024-04-15)

### Features


##### Photo

* Add getLabel ([4a917c](https://github.com/liquiddesign/eshop/commit/4a917c727056f4ae807e91db0b1193eef66246d7))


---

## [2.1.193](https://github.com/liquiddesign/eshop/compare/v2.1.192...v2.1.193) (2024-04-15)

### Features


##### Photo

* New presenter, import/export ([54e265](https://github.com/liquiddesign/eshop/commit/54e265697f1ee0476c8e42aeca860a48dd9e2d24))

##### Product Repository

* GetGroupedMergedProducts don't return empty products ([fafe98](https://github.com/liquiddesign/eshop/commit/fafe985ebf199a2f002561f5da9e21c32fab4fad))


---

## [2.1.192](https://github.com/liquiddesign/eshop/compare/v2.1.191...v2.1.192) (2024-04-12)

### Features


##### Admin

* Change date format ([703b8e](https://github.com/liquiddesign/eshop/commit/703b8ef79ec01104d65d82ec0c3fdbaf7ffc1c46))
* Add filters ([2593fc](https://github.com/liquiddesign/eshop/commit/2593fc2566a367db2ae66619af0f7f9d20793f1f))

##### Admin Pricelists

* Dont filter shops strictly ([026abd](https://github.com/liquiddesign/eshop/commit/026abd56d44e823742f385c7d57203e9bd5ede49))

##### Admin Product Form

* Add customContainer ([a1a002](https://github.com/liquiddesign/eshop/commit/a1a0023ab4ec1f6db8744b64bd60d001fe11627c))

##### Prices

* Add hidden price to cache ([edc469](https://github.com/liquiddesign/eshop/commit/edc4698241756bc80be16954e411d1a1de6cdd62))
* New Prices grid, hidden attribute and filters ([5f1980](https://github.com/liquiddesign/eshop/commit/5f19801c9778e93f447d3475a80d84432a43581f))


---

## [2.1.191](https://github.com/liquiddesign/eshop/compare/v2.1.190...v2.1.191) (2024-04-10)

### Bug Fixes


##### Product Exporter

* Change fetching of Page to separate query due to performance ([b8b63e](https://github.com/liquiddesign/eshop/commit/b8b63eee5269a925e8267ca31fcb347fbb357079))


---

## [2.1.190](https://github.com/liquiddesign/eshop/compare/v2.1.189...v2.1.190) (2024-04-10)

### Features


##### Product Exporter

* Change fetching of Page to separate query due to performance ([f78a5b](https://github.com/liquiddesign/eshop/commit/f78a5bc6ca9c22dd9b067c538d3c09a79b6ebd60))


---

## [2.1.189](https://github.com/liquiddesign/eshop/compare/v2.1.188...v2.1.189) (2024-04-10)

### Bug Fixes


##### Supplier Product

* Fill Product content if it is empty if lock is zero ([e6f84b](https://github.com/liquiddesign/eshop/commit/e6f84b7a45e4d6bf0903752ac5ee7d51f74ddd28))


---

## [2.1.188](https://github.com/liquiddesign/eshop/compare/v2.1.187...v2.1.188) (2024-04-10)

### Bug Fixes


##### Product Grid Filters Factory

* Category filter - allow all category types ([13dc62](https://github.com/liquiddesign/eshop/commit/13dc6270dc1a8dc4bcc49bb75ca1c3b4d255d594))


---

## [2.1.187](https://github.com/liquiddesign/eshop/compare/v2.1.186...v2.1.187) (2024-04-10)

### Features

* Filter fix, supplier naming, private to protected ([ed9031](https://github.com/liquiddesign/eshop/commit/ed9031946d54329961b2ef93b146c2c7aba29158))

##### Admin Visibility List Presenter

* Add Supplier filter ([f84139](https://github.com/liquiddesign/eshop/commit/f84139dedd5c354da51274468271d5069b05d88b))


---

## [2.1.186](https://github.com/liquiddesign/eshop/compare/v2.1.185...v2.1.186) (2024-04-09)

### Features


##### Product Importer

* Add default selection of primaryCategory if no primaryCategories are supplied ([f30114](https://github.com/liquiddesign/eshop/commit/f30114535300f8984c857b27d081a277b45d134f))


---

## [2.1.185](https://github.com/liquiddesign/eshop/compare/v2.1.184...v2.1.185) (2024-04-09)

### Bug Fixes


##### Product Export

* Change name of perex ([41f640](https://github.com/liquiddesign/eshop/commit/41f6404585c59cdaa5d21aee673af6db7e3333e1))


---

## [2.1.184](https://github.com/liquiddesign/eshop/compare/v2.1.183...v2.1.184) (2024-04-08)

### Bug Fixes


##### Products Cache

* Zero prices ([fd8977](https://github.com/liquiddesign/eshop/commit/fd8977445e2214a3f08a297b464d35831fe19058))


---

## [2.1.183](https://github.com/liquiddesign/eshop/compare/v2.1.182...v2.1.183) (2024-04-08)

### Features


##### Product

* Better types, ProductTester filter change ([103a83](https://github.com/liquiddesign/eshop/commit/103a8317995909748a40427861bf1b4116285c39))


---

## [2.1.182](https://github.com/liquiddesign/eshop/compare/v2.1.181...v2.1.182) (2024-04-05)

### Features


##### Admin Category

* Add code column to types grid ([e402af](https://github.com/liquiddesign/eshop/commit/e402afd1fbca6123791fa067e9397b4b2cf27794))

### Bug Fixes


##### Admin Product Form

* Move MUTATION_SELECTOR to top to be visible on all tabs ([dab3fd](https://github.com/liquiddesign/eshop/commit/dab3fd5106010269c5baccfa1e28e5463ecee3cd))


---

## [2.1.181](https://github.com/liquiddesign/eshop/compare/v2.1.180...v2.1.181) (2024-04-05)

### Bug Fixes


##### Product Importer

* Import categories ([f9537d](https://github.com/liquiddesign/eshop/commit/f9537dffd7ce2e74a8a8e9330e1c805b5f03a109))


---

## [2.1.180](https://github.com/liquiddesign/eshop/compare/v2.1.179...v2.1.180) (2024-04-04)

### Features


##### Admin Attribute

* Add cache clearing ([fbe76f](https://github.com/liquiddesign/eshop/commit/fbe76f5cb6c2b104feec7c33863e3ba3aa9b763e))


---

## [2.1.179](https://github.com/liquiddesign/eshop/compare/v2.1.178...v2.1.179) (2024-04-04)

### Features


##### Category

* Add config for showDescendantProducts ([abad5a](https://github.com/liquiddesign/eshop/commit/abad5ad6c6676d51d38f7f53341e4ed038b6005c))


---

## [2.1.178](https://github.com/liquiddesign/eshop/compare/v2.1.177...v2.1.178) (2024-04-04)

### Features


##### Category

* Add showDescendantProducts ([16c44c](https://github.com/liquiddesign/eshop/commit/16c44cfe1e280584083beec01b953dea2c12ec5a))


---

## [2.1.177](https://github.com/liquiddesign/eshop/compare/v2.1.176...v2.1.177) (2024-04-04)

### Features


##### Admin Pricelist Presenter

* Add custom Supplier names columns ([d33ec5](https://github.com/liquiddesign/eshop/commit/d33ec5db211523539aba015b55295e35d11c157a))


---

## [2.1.176](https://github.com/liquiddesign/eshop/compare/v2.1.175...v2.1.176) (2024-04-01)

### Bug Fixes


##### Checkout Manager

* If no cart, it returned random CartItem of Product. Now it returns null. ([bf22bf](https://github.com/liquiddesign/eshop/commit/bf22bfbe00c73b9b85e5a0ed3c38192708d0f45c))


---

## [2.1.175](https://github.com/liquiddesign/eshop/compare/v2.1.174...v2.1.175) (2024-03-30)

### Features


##### Product List

* Add currencies to template ([79c74c](https://github.com/liquiddesign/eshop/commit/79c74cd7bdfd7af48e402162bba99f44ed128e98))


---

## [2.1.174](https://github.com/liquiddesign/eshop/compare/v2.1.173...v2.1.174) (2024-03-28)

### Features


##### Purchase

* Filter external DeliveryType IDs by Shop ([b8b679](https://github.com/liquiddesign/eshop/commit/b8b679d76256f165c5877f75f8a1630180adf512))


---

## [2.1.173](https://github.com/liquiddesign/eshop/compare/v2.1.172...v2.1.173) (2024-03-27)

### Bug Fixes


##### Admin Product

* Default filterColumns config ([9b4f11](https://github.com/liquiddesign/eshop/commit/9b4f1155e5c60fc2094964f60a6a686696f2f297))


---

## [2.1.172](https://github.com/liquiddesign/eshop/compare/v2.1.171...v2.1.172) (2024-03-27)

### Bug Fixes


##### Product Filter

* Add new callbacks to modify min max prices ([7f9b1d](https://github.com/liquiddesign/eshop/commit/7f9b1dec769a34aff31723ab2c3c05db9f196101))


---

## [2.1.171](https://github.com/liquiddesign/eshop/compare/v2.1.170...v2.1.171) (2024-03-27)

### Features


##### Product Filter

* Add mainPriceType to template ([81eacf](https://github.com/liquiddesign/eshop/commit/81eacfa290a766234995562901dec58b18a433cf))


---

## [2.1.170](https://github.com/liquiddesign/eshop/compare/v2.1.169...v2.1.170) (2024-03-26)

### Bug Fixes


##### Product Importer

* Change SEO column names ([35c8aa](https://github.com/liquiddesign/eshop/commit/35c8aa206107cb3f64b98ba543db00a4e1aa749a))


---

## [2.1.169](https://github.com/liquiddesign/eshop/compare/v2.1.168...v2.1.169) (2024-03-26)

### Bug Fixes


##### Product Importer

* Change SEO column names ([a7e328](https://github.com/liquiddesign/eshop/commit/a7e3281ff26aa18c410074ba34cf3a56a726dd5f))


---

## [2.1.168](https://github.com/liquiddesign/eshop/compare/v2.1.167...v2.1.168) (2024-03-26)

### Bug Fixes


##### Product Form

* Possible duplicate product content ([074210](https://github.com/liquiddesign/eshop/commit/074210eace47eedb5c2f3893511dfc2f257dbad6))

##### Product Importer

* Attributes values creation ([810403](https://github.com/liquiddesign/eshop/commit/8104031bae04cc3eeaf02ce97d930069268d3848))


---

## [2.1.167](https://github.com/liquiddesign/eshop/compare/v2.1.166...v2.1.167) (2024-03-26)

### Bug Fixes


##### Product Exporter

* Columns order ([dc07ff](https://github.com/liquiddesign/eshop/commit/dc07ff9f4d43be4d1a3000e29a26dd672e8f65a8))


---

## [2.1.166](https://github.com/liquiddesign/eshop/compare/v2.1.165...v2.1.166) (2024-03-26)

### Bug Fixes


##### Rich Snippet

* Breadcrumb.latte syntax fix ([67c9e6](https://github.com/liquiddesign/eshop/commit/67c9e6c683d610eef26031e9ca07a4aa712a915b))


---

## [2.1.165](https://github.com/liquiddesign/eshop/compare/v2.1.164...v2.1.165) (2024-03-25)

### Bug Fixes


##### Product Form

* Possible duplicate product content ([40140b](https://github.com/liquiddesign/eshop/commit/40140b2107413717ae5bd92b586deff6a2c7b068))


---

## [2.1.164](https://github.com/liquiddesign/eshop/compare/v2.1.163...v2.1.164) (2024-03-25)

### Bug Fixes


##### Product CSV import

* Possible duplicate product content ([17e8db](https://github.com/liquiddesign/eshop/commit/17e8db114078370ddf70ade4d1c97706a65bb95d))

### Code Refactoring


##### Category grid

* Filter columns by configuration ([a545e6](https://github.com/liquiddesign/eshop/commit/a545e68b2f33deea7e16bbf25cd3fcdf2aa0cfe4))


---

## [2.1.163](https://github.com/liquiddesign/eshop/compare/v2.1.162...v2.1.163) (2024-03-25)

### Bug Fixes


##### Admin- Product Grid

* Remove categories save in bulk form ([777fc2](https://github.com/liquiddesign/eshop/commit/777fc25323b7b74f66c984e63245f227b5b256ee))


---

## [2.1.162](https://github.com/liquiddesign/eshop/compare/v2.1.161...v2.1.162) (2024-03-25)

### Bug Fixes


##### Products Cache

* Order PriceLists and VisibilityLists correctly by secondary uuid ([44aa7b](https://github.com/liquiddesign/eshop/commit/44aa7b81a841a2f89596a4360b9160796d90e42f))


---

## [2.1.161](https://github.com/liquiddesign/eshop/compare/v2.1.160...v2.1.161) (2024-03-22)

### Bug Fixes

* Product list category breadcrumbs ([19face](https://github.com/liquiddesign/eshop/commit/19face3b862dc9a88ff6e758441972ab76b06ae1))


---

## [2.1.160](https://github.com/liquiddesign/eshop/compare/v2.1.159...v2.1.160) (2024-03-22)

### Code Refactoring

* Ftp images import ([f0105b](https://github.com/liquiddesign/eshop/commit/f0105bf25b26207e5b98aed69eef37af0d3a86c6))


---

## [2.1.159](https://github.com/liquiddesign/eshop/compare/v2.1.158...v2.1.159) (2024-03-21)

### Features


##### Products Cache

* Allow nullable producer in collection filter ([cf0e25](https://github.com/liquiddesign/eshop/commit/cf0e25e92a9a2774cfd90ce99fbf9b020c1ff84e))

### Bug Fixes


##### Products Cache

* Filter price >= 0 ([d52e98](https://github.com/liquiddesign/eshop/commit/d52e98b183f4a4d5b8a1e28affe90d1a96df3ebe))


---

## [2.1.158](https://github.com/liquiddesign/eshop/compare/v2.1.157...v2.1.158) (2024-03-21)

### Features


##### Comgate

* New callback to custom price calculation ([8a525c](https://github.com/liquiddesign/eshop/commit/8a525c3f4356aa7d8419677d04d2f26a734354a9))

##### Feeds

* Ceil all prices ([eb2a06](https://github.com/liquiddesign/eshop/commit/eb2a068bb96bb0e2048d73d0f3e25e1011802e99))


---

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

