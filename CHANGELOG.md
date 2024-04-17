<!--- BEGIN HEADER -->
# Changelog

All notable changes to this project will be documented in this file.
<!--- END HEADER -->

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

