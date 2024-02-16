<!--- BEGIN HEADER -->
# Changelog

All notable changes to this project will be documented in this file.
<!--- END HEADER -->

## [2.1.133](https://github.com/liquiddesign/eshop/compare/v2.1.132...v2.1.133) (2024-02-16)

### ⚠ BREAKING CHANGES

* Dont filter pricelists and visibilitylists by shop ([fe1dd4](https://github.com/liquiddesign/eshop/commit/fe1dd48bd88ca370d347273eb9df5a9690aef7e9))
* Customers and Accounts are now indexed uniquely by shop. You can create Customer or Account with same login/email for all shops. ([78c64f](https://github.com/liquiddesign/eshop/commit/78c64f6ffc3581343a7b54a55af9043883eb46f9))
* Add shops and ARES support to Customer ([78c64f](https://github.com/liquiddesign/eshop/commit/78c64f6ffc3581343a7b54a55af9043883eb46f9))

##### Dpd

* Don't throw exception on GetTrackingByParcelno, instead continue ([65cbd6](https://github.com/liquiddesign/eshop/commit/65cbd6a928f947805dd83e22c35f2607e95b1b3c))
* Migrate dpd changes from v1.4 ([efb64b](https://github.com/liquiddesign/eshop/commit/efb64bcff4906c0ff63afe4074f56b021073edca))

### Features

* Add commit check ([d3f1dc](https://github.com/liquiddesign/eshop/commit/d3f1dcc89b104f83ae1be4e1dd938401a7254f17))
* Cache childrenCustomers in ShopperUser, new helper methods on models, new editMode in CartList ([3d3bb4](https://github.com/liquiddesign/eshop/commit/3d3bb4282fb53dead613dea02d489f20eb2cb574))
* Change deprecated method calls, add form errors debugging helper ([2668f1](https://github.com/liquiddesign/eshop/commit/2668f1a93ca5ee14cc25e108751ec24e864d457a))
* Better memory efficiency of cache warmup ([f32039](https://github.com/liquiddesign/eshop/commit/f32039030d5c00f56648977a3489e9cf7315affa))
* Better merchants and parent customer rendering in grid ([39770d](https://github.com/liquiddesign/eshop/commit/39770d3a5f087ded9af38d9ee75ab99221990c1e))
* Make getCacheIndexToBeUsed public ([f71880](https://github.com/liquiddesign/eshop/commit/f71880c4acb7f8fd2ef39bbc643e8c6ae180d553))
* Change addresses ([e1b2c5](https://github.com/liquiddesign/eshop/commit/e1b2c501267b9bdfa79c1981d88b7ab4fb6482c8))
* Cache clean up ([747ed4](https://github.com/liquiddesign/eshop/commit/747ed42d9ba39d13a9e25f9bf97937fb0ce1838f))
* New cache ([a00f62](https://github.com/liquiddesign/eshop/commit/a00f62738a8bc76eb157881534641b57d026790f), [abe089](https://github.com/liquiddesign/eshop/commit/abe08926b0de4b3d1912d45c7757111374571676), [7203ea](https://github.com/liquiddesign/eshop/commit/7203eaa5a5fbb936744f3faed71f0ea88e124c46))
* New generation cache ([70217e](https://github.com/liquiddesign/eshop/commit/70217e36e1acb79243b58d834ac1ce3927f0397d), [891f5e](https://github.com/liquiddesign/eshop/commit/891f5e40bdafa143dc9ea1f202e8d46fbaf9c54f))
* Settings comgate ([562d01](https://github.com/liquiddesign/eshop/commit/562d0113f2b44e4590446d9a92e0c8b994ff3625))
* Debug ([d01b80](https://github.com/liquiddesign/eshop/commit/d01b805649baf261afd22093443db67fe23a9019))
* Products provider - better cache warming ([da18c6](https://github.com/liquiddesign/eshop/commit/da18c65ec1db47019cd9b3c2336955226901e28a))
* Products provider - new prices selecting and filtering ([933f45](https://github.com/liquiddesign/eshop/commit/933f45203b9ef9b00e534eab56dc9783b8ba1fe3))
* Products provider - faster insertions and index creation, separate table for prices ([6de62a](https://github.com/liquiddesign/eshop/commit/6de62a2de26c69a563f68e99520c332d61f14486))
* Pgsql products provider ([f3c704](https://github.com/liquiddesign/eshop/commit/f3c704867f1a0811a17a7d002ee39327f67dfd1c))
* Add sendSurvey to Purchase ([c2b9c1](https://github.com/liquiddesign/eshop/commit/c2b9c16c7963fa7ea90aa19f4a06bfe6a1007832))
* Unregistered group by shop ([c95eb1](https://github.com/liquiddesign/eshop/commit/c95eb1d81f73aea9ae037448603b68c797b0bb8c))
* New product image method ([376a13](https://github.com/liquiddesign/eshop/commit/376a133ce840d7b095ae015d3f2880b56ba2e496))
* Settings ([b5f6d2](https://github.com/liquiddesign/eshop/commit/b5f6d2aefdc43ff995cb2da22e368f05394572be))
* Better checkout ([a388fb](https://github.com/liquiddesign/eshop/commit/a388fba497cf7681a8017fc7beb947a69a24f91b))
* Customer groups by shop ([036e1b](https://github.com/liquiddesign/eshop/commit/036e1b59a4649751fa024e0f343328278e9b31e2))
* Address getName ([c5f29c](https://github.com/liquiddesign/eshop/commit/c5f29c9f090a5029002c080ae814568e1cfd6894))
* Log all orders ([7e3c0c](https://github.com/liquiddesign/eshop/commit/7e3c0c924d790346f7a63be9f8ae1802bc34e698))
* New products provider ([0abd82](https://github.com/liquiddesign/eshop/commit/0abd82511d7505b868504737923b278988ecf722), [e2036a](https://github.com/liquiddesign/eshop/commit/e2036a73beb8d8715ed30235dd08e7603f430d6a))
* GetFirstMainCategoryByShop ([d97149](https://github.com/liquiddesign/eshop/commit/d97149dc96d137f66206a7ef76f71cc7688bdc9f))
* Producer main categories as MxN ([ef6a2d](https://github.com/liquiddesign/eshop/commit/ef6a2d39caa081a59e08a447e054e4c3dae702b6))
* ProductWithFormattedPrices supports also numeric representation of primary price, new view methods ([0e5162](https://github.com/liquiddesign/eshop/commit/0e516263134ab7deba8c0da44ddca30357537055))
* Postgres next-gen cache ([b75bae](https://github.com/liquiddesign/eshop/commit/b75baedb000f1172d007e1b427f4fb7aba13a014))
* Extendable address form ([6c837f](https://github.com/liquiddesign/eshop/commit/6c837f283dd89406a4d7f42134756b33ce723a31))
* Adding getRelatedItems ([ac5742](https://github.com/liquiddesign/eshop/commit/ac5742d0839fab6225815cac1beb45ced7aee6d7))
* Add method helper to Customer, better error handling in ProductList ([b8a3ea](https://github.com/liquiddesign/eshop/commit/b8a3eaa1340d4fea103af4f57da25f9c5305dfcb))
* Complaint note height to 8 lines ([f5b182](https://github.com/liquiddesign/eshop/commit/f5b1828f90b888c154ef959b3702c60739f69237))
* New helper methods ([58ddec](https://github.com/liquiddesign/eshop/commit/58ddec88a9e6c267c96ab613c3aa3705e09c4c6b))
* Filter supplier categories with like ([1b98ad](https://github.com/liquiddesign/eshop/commit/1b98ad8a857d77412cc474d4f09c202d920f7a87))
* Add primarycategory filters to ProductProvider ([de7500](https://github.com/liquiddesign/eshop/commit/de750051c467c919093dfb5572ec2583bc412699))
* Add relations filters to ProductsProvider ([c0563e](https://github.com/liquiddesign/eshop/commit/c0563e6eca917912d408a951c15afedbbfd72723))
* Add properties due to compatibility with v1.4 ([fc96a2](https://github.com/liquiddesign/eshop/commit/fc96a220805668eec2ef31ed3e38664d4cb44444), [e4c289](https://github.com/liquiddesign/eshop/commit/e4c289b91fe00455d2f87d06b6be7bcbab3f5d1f), [bbc020](https://github.com/liquiddesign/eshop/commit/bbc020b6b9adeb92dd3ed5665a4e48b8d78fb0e6))
* Add internalName due to compatibility with v1.4 ([9b6430](https://github.com/liquiddesign/eshop/commit/9b64302e59ebc88b43f7e53132f6388879d931d2))
* Add $formatDecimals to filterPrice ([4476e8](https://github.com/liquiddesign/eshop/commit/4476e8f184faa8930142ee810531c27db5b5a8cd))
* Add shops select to order grid ([7643d7](https://github.com/liquiddesign/eshop/commit/7643d79d73e3d8690b2808904b589c9cba2ee3c4))
* Indexes, import urls per shop ([c26f87](https://github.com/liquiddesign/eshop/commit/c26f87884fb6e720d4ab6974f5da889f000a5dc9))
* New product getter service ([6c7dc2](https://github.com/liquiddesign/eshop/commit/6c7dc2746df82c8120fcc47ce8641fdd43c22a04))
* Add generic to PricelistRepository ([1dec8e](https://github.com/liquiddesign/eshop/commit/1dec8e153c4a5f9231e01d58d9cac867855eda7f))
* New LostPasswordService ([ce68fa](https://github.com/liquiddesign/eshop/commit/ce68faf05db6d159148d9d8dbe1348b8e0d88075))
* Add methods to extend grids and forms in CustomerPresenter ([86cd61](https://github.com/liquiddesign/eshop/commit/86cd61be23f7953477d17556d2e54e094f87c6db))
* Add shops to CustomerGroup ([a15cea](https://github.com/liquiddesign/eshop/commit/a15cea8266f52ca321314def62c3f006b414a442))
* Move account unique validation to admin package, add better customer unique validation ([3fee9d](https://github.com/liquiddesign/eshop/commit/3fee9db99fb16017b2c8efe384fb59a9f9eb8ba2))
* Add zasilkovna delivery setting type, show ppl settings even without ppl service ([ac8005](https://github.com/liquiddesign/eshop/commit/ac80059ed0bc78483db32719a58683ba5e9078df))
* Use Ares v2.0 ([458a9f](https://github.com/liquiddesign/eshop/commit/458a9f8825d8b02f6cdbcd7d77234979cf0e5f53))

##### Admin

* Add filter of category assigns in attribute grid ([a2ee21](https://github.com/liquiddesign/eshop/commit/a2ee21eefd52588c197b4a6a7074b07e94b916fb))
* Better scripts ([ac0275](https://github.com/liquiddesign/eshop/commit/ac027557ed3251fca9712d0db0f1e3766876c665))
* Order price and visibility lists in groups by priority ([864b2e](https://github.com/liquiddesign/eshop/commit/864b2e6661d209f36e2b704010c6903ec1e4b623))
* Allow raw url in scripts ([3cab72](https://github.com/liquiddesign/eshop/commit/3cab72e97fb71aa17a50cedf3d2c555830512116), [be1873](https://github.com/liquiddesign/eshop/commit/be187352d1e5064f437ed822f491aa4b75f6202b), [f7ca73](https://github.com/liquiddesign/eshop/commit/f7ca73a0ec0efdfed3663f3debf3cd9260db7a05))
* Highlight store amount from set suppliers ([19e9b0](https://github.com/liquiddesign/eshop/commit/19e9b0ea2d448bb89e53b0a346d9b3e63080e23a))
* Add code input to supplier product detail ([b37c20](https://github.com/liquiddesign/eshop/commit/b37c20d5db08c08d10ca20f57cfb97241700abc8))
* Add customer constraints, fix payment in order detail ([a3451c](https://github.com/liquiddesign/eshop/commit/a3451cc5a4222ee698edd17820acac8aad002c58))
* Add filters to visibility list presenter ([217278](https://github.com/liquiddesign/eshop/commit/217278e1a576105d41f502bc40910c100a3dd60d))
* Add detail filtering of order log to orders grid ([bc3915](https://github.com/liquiddesign/eshop/commit/bc391530a3bb681fae55c53a740678087eb00b5b))
* Add multiple csv export of orders in grid to zip ([771d12](https://github.com/liquiddesign/eshop/commit/771d122fcfaec21101ad559e5f5349bb3ae6acfa))
* Add button to recalculatePrices to orders grid ([692e7c](https://github.com/liquiddesign/eshop/commit/692e7c78e7db9a84901e412ae930b3f4308c2b2f))
* Add method to recalculate prices multiple in grid ([99285c](https://github.com/liquiddesign/eshop/commit/99285cfcd061754d8bea44cefa765c987b5f53c6))
* Add method to recalculate prices of items in order ([7e41cb](https://github.com/liquiddesign/eshop/commit/7e41cb51c5512dbd394312fdcacbfa50dd62e24d))

##### Admin-order

* Add accunt name to order detail and hover ([4d593a](https://github.com/liquiddesign/eshop/commit/4d593a243b9020784fa1327c01d7c89f7554d920))

##### Checkout Manager

* Inject Integrations ([ae92b0](https://github.com/liquiddesign/eshop/commit/ae92b03b6cedf75f3158cfb184f57c8c53ae38b5))

##### Customer, Account

* Added callbacks and functions to extend columns and filter inputs in grids; ([331f4d](https://github.com/liquiddesign/eshop/commit/331f4dde68fedb6a4ed4a6df0c6c9cc64d5b65d2))

##### Products Provider

* Dont cache provider results ([57921d](https://github.com/liquiddesign/eshop/commit/57921d3bcad602eb3d503dffe759ef73bfa842aa))

##### Relations

* Add support to filter Related items per shop(s) ([a26d6e](https://github.com/liquiddesign/eshop/commit/a26d6ee59a953639aa6722bb0a325b1aee8a1c71))

### Bug Fixes

* Check if pricelist assigned discount is same as coupon applied in cart to apply pricelist ([1faae1](https://github.com/liquiddesign/eshop/commit/1faae155d3d0d739131c6fc1f548e0c6aefb1e0e))
* Cache pricelist filtering, favourite pricelists ([a15c8e](https://github.com/liquiddesign/eshop/commit/a15c8eadcb25173b5dcd22e66cf3c9de237fb637))
* Show product form errors ([10b523](https://github.com/liquiddesign/eshop/commit/10b5235db4a49deebef2bf48343fb50d91572e8d))
* Comgate ([b0fd8d](https://github.com/liquiddesign/eshop/commit/b0fd8d5b0bdd2f5747411fc0dfe4eb1c0ff2cc0a))
* Cache ([a92bac](https://github.com/liquiddesign/eshop/commit/a92bacb0758a1efbe147b74a117ba852d2acb4cb), [a935bc](https://github.com/liquiddesign/eshop/commit/a935bcc7bc55f2d1a0ba34248f867c3ffc0f1082))
* GetProductPricesFormattedFromCartItem bad multiply remove ([d0034e](https://github.com/liquiddesign/eshop/commit/d0034ef2774225860c58e217a3822b662ac86d05))
* ProductsProvider changes ([d2ec9e](https://github.com/liquiddesign/eshop/commit/d2ec9eadfbeadbc1cdea39642671bc7cd1fe3f51))
* ProductFilter::getSystemicAttributeValues - rewrite sql for producers for better performance ([94a58b](https://github.com/liquiddesign/eshop/commit/94a58be23d69347d1a40e08ef68753f6c3346a1e))
* Products provider - order price correctly to new format ([ded586](https://github.com/liquiddesign/eshop/commit/ded586809e84ff3fa0741ea0c7b03f6f421e20c8))
* Correct filter of categories in product admin grid ([03dd50](https://github.com/liquiddesign/eshop/commit/03dd5000eb3a631783cd3bd39a0767e11ed057b2))
* Typo fix ([c8c8a2](https://github.com/liquiddesign/eshop/commit/c8c8a26769058712928713d3342b8999b56661ff))
* Preload items of productlist in render method to properly load paginator in advance ([58df6a](https://github.com/liquiddesign/eshop/commit/58df6ab6b28cd94dd672b93e64db08d1c23735fb))
* Assign correct shops in registration ([8c3c73](https://github.com/liquiddesign/eshop/commit/8c3c73d59a23b1ba7e26ea12bcbc3ba0235b8c54))
* Order detail ([d2d681](https://github.com/liquiddesign/eshop/commit/d2d6812b6a145fb1da4fc61525ba4de0642fb447))
* Item count of PriceListItems grid ([a8183c](https://github.com/liquiddesign/eshop/commit/a8183c0d06b8933bcee3f9867b09624631b4cc3a), [480da2](https://github.com/liquiddesign/eshop/commit/480da28d3d9ad5ec16fb40ea7a09f31eabbf776a))
* Rounding of discountPercent ([ea5360](https://github.com/liquiddesign/eshop/commit/ea5360c200e8ee503118a3095a9645fca78f5f1f))
* Discount coupon conditions ([946476](https://github.com/liquiddesign/eshop/commit/9464764fc4cb23b66b53dc45eebdab2f0b817cf4))
* Handle missing prices in cache warmup ([773b5a](https://github.com/liquiddesign/eshop/commit/773b5a196a6d09dec453cd2f69ee2aaa1d872e68))
* Binder names for OR attributes filtration ([7732a9](https://github.com/liquiddesign/eshop/commit/7732a9183eb9b50c42f6a5ea9f34cf95b7d01c7b))
* ZEND_FETCH_DIM_W ([026d91](https://github.com/liquiddesign/eshop/commit/026d914a80806d75d9d1a1243299bbaecbe1198d))
* Types in grouping, registration config ([25299e](https://github.com/liquiddesign/eshop/commit/25299ed43c819859533c9eeacf2c9f7c628a0083))
* Config wrong type ([8c7ecf](https://github.com/liquiddesign/eshop/commit/8c7ecff337440c7494b7bfe6e5955f797c81b864))
* Hide some filters ([c654ef](https://github.com/liquiddesign/eshop/commit/c654ef835bfca9642162e4ba98cf1392febef69a))
* Type fix ([05ad24](https://github.com/liquiddesign/eshop/commit/05ad243ea4e220a42f2421932fe2c126975dc06b))
* Category counts ([5e97db](https://github.com/liquiddesign/eshop/commit/5e97db638bc909643879ca9c1e8bf7ea1fcec12e))
* Php 8.3 compatibility ([f4b927](https://github.com/liquiddesign/eshop/commit/f4b9270cee657b02b99d43392a6e324460c2f43e))
* Require attribute in filters, add zasilkovna setting ([16e0be](https://github.com/liquiddesign/eshop/commit/16e0be49ea6d5bb08a0bb23543107b16e25ad0b0))
* Feeds typo fix ([0ca78d](https://github.com/liquiddesign/eshop/commit/0ca78da28329520c1857d5d6e105ae4c72b48b1c))
* Content selection ([57b265](https://github.com/liquiddesign/eshop/commit/57b2655d4beb71bb300f9d9193ddaae2ac665a7e))
* Delivery combination validation ([18128c](https://github.com/liquiddesign/eshop/commit/18128c6188ccbc98f2144e2fc356369d0a7ec6ac))
* Dont enforce joining product content in get products, only select ([2732f4](https://github.com/liquiddesign/eshop/commit/2732f413f6d35ede90f43f3c0a9a3ff2843c128b))
* Price filters validation ([f24282](https://github.com/liquiddesign/eshop/commit/f242825ea839ca1be40709b46acbc4e735a43728))
* Add default filters to category counts cache from provider ([be547a](https://github.com/liquiddesign/eshop/commit/be547aa395b2d121d98802fda7be4e40c925d8a9))
* Various ([171ec0](https://github.com/liquiddesign/eshop/commit/171ec0f20f1e17836c7fffbca1313b179ad5f8ca))
* Showbar ([31162a](https://github.com/liquiddesign/eshop/commit/31162aea84ce9e187816f028d7037fdf0cba88a9))
* Find customer by shop in order creation ([c2f871](https://github.com/liquiddesign/eshop/commit/c2f87108282be369a1f4bf4722cd53e1036e9335))

##### Admin

* Show correct ancestor categories in detail ([b405a3](https://github.com/liquiddesign/eshop/commit/b405a3c60f38f2d46b34e227db6be764e9d19f0a))
* Recalculate order items prices with high precision ([1b1dc5](https://github.com/liquiddesign/eshop/commit/1b1dc52658229cbc52c2cf01c2241af249021552))
* Show exportCsv and exportEdi buttons only if configuration allows it ([04ec40](https://github.com/liquiddesign/eshop/commit/04ec400c71c044a187c33cde921040d4cfcd8fef))

##### Cache

* Improvements in cache cleaning ([c3fc55](https://github.com/liquiddesign/eshop/commit/c3fc55ca32f8a558b44734a27ea12b77bee62622))

##### Comgate

* Round price in payment correctly ([c4691e](https://github.com/liquiddesign/eshop/commit/c4691ea174a15b8f65190faf1e49249dba652ec0))

##### Dpd

* Increase soap client timeout ([174c1f](https://github.com/liquiddesign/eshop/commit/174c1f12410d83247667a1865dfced5a201720b5))

##### Invoice

* Constraints ([44b741](https://github.com/liquiddesign/eshop/commit/44b7418b6ea624eaec3feec26976e1d40f1fe813))

##### Products Provider

* Throw exception if related filter values are invalid instead of warning ([d41d7d](https://github.com/liquiddesign/eshop/commit/d41d7d19d9593b90a470c40681f435f36f0dbcbe), [bdc6a9](https://github.com/liquiddesign/eshop/commit/bdc6a9ddc10cda6da39b02b6bc60faf2e806af19))

##### Relations

* New import ([c8b785](https://github.com/liquiddesign/eshop/commit/c8b785ce153b199b44bec7625a7575801dcb782a))

### Builds

* Add script to generate changelog ([910529](https://github.com/liquiddesign/eshop/commit/9105293a3472937267165a29b246d23fee2d74f0))
* Github actions with PHP 8.2 ([ffe139](https://github.com/liquiddesign/eshop/commit/ffe139f090bab0783c0aa3850b856f6ec2b7d7a7))

### Chores

* Rename current changelog as old ([3be33e](https://github.com/liquiddesign/eshop/commit/3be33e64c6c7b63e90b31fa115cc64a86de3bc99))
* Relation method ([143b9d](https://github.com/liquiddesign/eshop/commit/143b9d5ea244ffb5f6ddbe92fd9b332be1775d8e))
* Codestyle ([1f2510](https://github.com/liquiddesign/eshop/commit/1f251047c609bd447fe9fd3d6bbc737537a44438))
* Update actions branches ([4b9c5e](https://github.com/liquiddesign/eshop/commit/4b9c5e71fea3bae6bc6a80fe2e1b0ce6cb9030ac))
* Cs fix ([225d38](https://github.com/liquiddesign/eshop/commit/225d380e9f63cea22d06ea26b9a5bdb1e2eab703))


---

