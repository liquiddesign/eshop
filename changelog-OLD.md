<!--- BEGIN HEADER -->
# Changelog

All notable changes to this project will be documented in this file.
<!--- END HEADER -->

## [3.0.0](https://github.com/liquiddesign/eshop/compare/v2.1.146...v3.0.0) (2024-03-06)

### ⚠ BREAKING CHANGES

* Add shops and ARES support to Customer ([78c64f](https://github.com/liquiddesign/eshop/commit/78c64f6ffc3581343a7b54a55af9043883eb46f9))
* Customers and Accounts are now indexed uniquely by shop. You can create Customer or Account with same login/email for all shops. ([78c64f](https://github.com/liquiddesign/eshop/commit/78c64f6ffc3581343a7b54a55af9043883eb46f9))
* Dont filter pricelists and visibilitylists by shop ([fe1dd4](https://github.com/liquiddesign/eshop/commit/fe1dd48bd88ca370d347273eb9df5a9690aef7e9))
* Remove old products providers ([68393e](https://github.com/liquiddesign/eshop/commit/68393e61b97053453dda18bcf6feffd3505a22b8))
* Revert Dependency injection aggregates ([e02451](https://github.com/liquiddesign/eshop/commit/e02451f597d5a4fc675cdad8a5a633158d075e25))

##### Dpd

* Don't throw exception on GetTrackingByParcelno, instead continue ([65cbd6](https://github.com/liquiddesign/eshop/commit/65cbd6a928f947805dd83e22c35f2607e95b1b3c))
* Migrate dpd changes from v1.4 ([efb64b](https://github.com/liquiddesign/eshop/commit/efb64bcff4906c0ff63afe4074f56b021073edca))

### Features

* Add commit check ([d3f1dc](https://github.com/liquiddesign/eshop/commit/d3f1dcc89b104f83ae1be4e1dd938401a7254f17))
* Add $formatDecimals to filterPrice ([4476e8](https://github.com/liquiddesign/eshop/commit/4476e8f184faa8930142ee810531c27db5b5a8cd))
* Add generic to PricelistRepository ([1dec8e](https://github.com/liquiddesign/eshop/commit/1dec8e153c4a5f9231e01d58d9cac867855eda7f))
* Adding getRelatedItems ([ac5742](https://github.com/liquiddesign/eshop/commit/ac5742d0839fab6225815cac1beb45ced7aee6d7))
* Add internalName due to compatibility with v1.4 ([9b6430](https://github.com/liquiddesign/eshop/commit/9b64302e59ebc88b43f7e53132f6388879d931d2))
* Add method helper to Customer, better error handling in ProductList ([b8a3ea](https://github.com/liquiddesign/eshop/commit/b8a3eaa1340d4fea103af4f57da25f9c5305dfcb))
* Add methods to extend grids and forms in CustomerPresenter ([86cd61](https://github.com/liquiddesign/eshop/commit/86cd61be23f7953477d17556d2e54e094f87c6db))
* Add primarycategory filters to ProductProvider ([de7500](https://github.com/liquiddesign/eshop/commit/de750051c467c919093dfb5572ec2583bc412699))
* Add properties due to compatibility with v1.4 ([fc96a2](https://github.com/liquiddesign/eshop/commit/fc96a220805668eec2ef31ed3e38664d4cb44444), [e4c289](https://github.com/liquiddesign/eshop/commit/e4c289b91fe00455d2f87d06b6be7bcbab3f5d1f), [bbc020](https://github.com/liquiddesign/eshop/commit/bbc020b6b9adeb92dd3ed5665a4e48b8d78fb0e6))
* Add relations filters to ProductsProvider ([c0563e](https://github.com/liquiddesign/eshop/commit/c0563e6eca917912d408a951c15afedbbfd72723))
* Address getName ([c5f29c](https://github.com/liquiddesign/eshop/commit/c5f29c9f090a5029002c080ae814568e1cfd6894))
* Add sendSurvey to Purchase ([c2b9c1](https://github.com/liquiddesign/eshop/commit/c2b9c16c7963fa7ea90aa19f4a06bfe6a1007832))
* Add shops select to order grid ([7643d7](https://github.com/liquiddesign/eshop/commit/7643d79d73e3d8690b2808904b589c9cba2ee3c4))
* Add shops to CustomerGroup ([a15cea](https://github.com/liquiddesign/eshop/commit/a15cea8266f52ca321314def62c3f006b414a442))
* Add zasilkovna delivery setting type, show ppl settings even without ppl service ([ac8005](https://github.com/liquiddesign/eshop/commit/ac80059ed0bc78483db32719a58683ba5e9078df))
* Better checkout ([a388fb](https://github.com/liquiddesign/eshop/commit/a388fba497cf7681a8017fc7beb947a69a24f91b))
* Better memory efficiency of cache warmup ([f32039](https://github.com/liquiddesign/eshop/commit/f32039030d5c00f56648977a3489e9cf7315affa))
* Better merchants and parent customer rendering in grid ([39770d](https://github.com/liquiddesign/eshop/commit/39770d3a5f087ded9af38d9ee75ab99221990c1e))
* Cache childrenCustomers in ShopperUser, new helper methods on models, new editMode in CartList ([3d3bb4](https://github.com/liquiddesign/eshop/commit/3d3bb4282fb53dead613dea02d489f20eb2cb574))
* Cache clean up ([747ed4](https://github.com/liquiddesign/eshop/commit/747ed42d9ba39d13a9e25f9bf97937fb0ce1838f))
* Change addresses ([e1b2c5](https://github.com/liquiddesign/eshop/commit/e1b2c501267b9bdfa79c1981d88b7ab4fb6482c8))
* Change deprecated method calls, add form errors debugging helper ([2668f1](https://github.com/liquiddesign/eshop/commit/2668f1a93ca5ee14cc25e108751ec24e864d457a))
* Complaint note height to 8 lines ([f5b182](https://github.com/liquiddesign/eshop/commit/f5b1828f90b888c154ef959b3702c60739f69237))
* Customer groups by shop ([036e1b](https://github.com/liquiddesign/eshop/commit/036e1b59a4649751fa024e0f343328278e9b31e2))
* Debug ([d01b80](https://github.com/liquiddesign/eshop/commit/d01b805649baf261afd22093443db67fe23a9019))
* Extendable address form ([6c837f](https://github.com/liquiddesign/eshop/commit/6c837f283dd89406a4d7f42134756b33ce723a31))
* Filter supplier categories with like ([1b98ad](https://github.com/liquiddesign/eshop/commit/1b98ad8a857d77412cc474d4f09c202d920f7a87))
* GetFirstMainCategoryByShop ([d97149](https://github.com/liquiddesign/eshop/commit/d97149dc96d137f66206a7ef76f71cc7688bdc9f))
* Indexes, import urls per shop ([c26f87](https://github.com/liquiddesign/eshop/commit/c26f87884fb6e720d4ab6974f5da889f000a5dc9))
* Log all orders ([7e3c0c](https://github.com/liquiddesign/eshop/commit/7e3c0c924d790346f7a63be9f8ae1802bc34e698))
* Make getCacheIndexToBeUsed public ([f71880](https://github.com/liquiddesign/eshop/commit/f71880c4acb7f8fd2ef39bbc643e8c6ae180d553))
* Move account unique validation to admin package, add better customer unique validation ([3fee9d](https://github.com/liquiddesign/eshop/commit/3fee9db99fb16017b2c8efe384fb59a9f9eb8ba2))
* New cache ([a00f62](https://github.com/liquiddesign/eshop/commit/a00f62738a8bc76eb157881534641b57d026790f), [abe089](https://github.com/liquiddesign/eshop/commit/abe08926b0de4b3d1912d45c7757111374571676), [7203ea](https://github.com/liquiddesign/eshop/commit/7203eaa5a5fbb936744f3faed71f0ea88e124c46))
* New generation cache ([70217e](https://github.com/liquiddesign/eshop/commit/70217e36e1acb79243b58d834ac1ce3927f0397d), [891f5e](https://github.com/liquiddesign/eshop/commit/891f5e40bdafa143dc9ea1f202e8d46fbaf9c54f))
* New helper methods ([58ddec](https://github.com/liquiddesign/eshop/commit/58ddec88a9e6c267c96ab613c3aa3705e09c4c6b))
* New LostPasswordService ([ce68fa](https://github.com/liquiddesign/eshop/commit/ce68faf05db6d159148d9d8dbe1348b8e0d88075))
* New product getter service ([6c7dc2](https://github.com/liquiddesign/eshop/commit/6c7dc2746df82c8120fcc47ce8641fdd43c22a04))
* New product image method ([376a13](https://github.com/liquiddesign/eshop/commit/376a133ce840d7b095ae015d3f2880b56ba2e496))
* New products provider ([0abd82](https://github.com/liquiddesign/eshop/commit/0abd82511d7505b868504737923b278988ecf722), [e2036a](https://github.com/liquiddesign/eshop/commit/e2036a73beb8d8715ed30235dd08e7603f430d6a))
* Pgsql products provider ([f3c704](https://github.com/liquiddesign/eshop/commit/f3c704867f1a0811a17a7d002ee39327f67dfd1c))
* Postgres next-gen cache ([b75bae](https://github.com/liquiddesign/eshop/commit/b75baedb000f1172d007e1b427f4fb7aba13a014))
* Producer main categories as MxN ([ef6a2d](https://github.com/liquiddesign/eshop/commit/ef6a2d39caa081a59e08a447e054e4c3dae702b6))
* Products provider - better cache warming ([da18c6](https://github.com/liquiddesign/eshop/commit/da18c65ec1db47019cd9b3c2336955226901e28a))
* Products provider - faster insertions and index creation, separate table for prices ([6de62a](https://github.com/liquiddesign/eshop/commit/6de62a2de26c69a563f68e99520c332d61f14486))
* Products provider - new prices selecting and filtering ([933f45](https://github.com/liquiddesign/eshop/commit/933f45203b9ef9b00e534eab56dc9783b8ba1fe3))
* ProductWithFormattedPrices supports also numeric representation of primary price, new view methods ([0e5162](https://github.com/liquiddesign/eshop/commit/0e516263134ab7deba8c0da44ddca30357537055))
* Settings ([b5f6d2](https://github.com/liquiddesign/eshop/commit/b5f6d2aefdc43ff995cb2da22e368f05394572be))
* Settings comgate ([562d01](https://github.com/liquiddesign/eshop/commit/562d0113f2b44e4590446d9a92e0c8b994ff3625))
* Unregistered group by shop ([c95eb1](https://github.com/liquiddesign/eshop/commit/c95eb1d81f73aea9ae037448603b68c797b0bb8c))
* Use Ares v2.0 ([74a59d](https://github.com/liquiddesign/eshop/commit/74a59d3f7ba77dc40b670e8542f45efae5fd5249), [458a9f](https://github.com/liquiddesign/eshop/commit/458a9f8825d8b02f6cdbcd7d77234979cf0e5f53))

##### Admin

* Add button to recalculatePrices to orders grid ([692e7c](https://github.com/liquiddesign/eshop/commit/692e7c78e7db9a84901e412ae930b3f4308c2b2f), [7fa569](https://github.com/liquiddesign/eshop/commit/7fa569b6c0bd43b1540021bb25f2505c0259d67e))
* Add code input to supplier product detail ([b37c20](https://github.com/liquiddesign/eshop/commit/b37c20d5db08c08d10ca20f57cfb97241700abc8))
* Add customer constraints, fix payment in order detail ([a3451c](https://github.com/liquiddesign/eshop/commit/a3451cc5a4222ee698edd17820acac8aad002c58))
* Add detail filtering of order log to orders grid ([bc3915](https://github.com/liquiddesign/eshop/commit/bc391530a3bb681fae55c53a740678087eb00b5b), [7859ac](https://github.com/liquiddesign/eshop/commit/7859ac2551373b5462ee3dd09ee421acb0b0a6cf))
* Add filter of category assigns in attribute grid ([a2ee21](https://github.com/liquiddesign/eshop/commit/a2ee21eefd52588c197b4a6a7074b07e94b916fb))
* Add filters to visibility list presenter ([217278](https://github.com/liquiddesign/eshop/commit/217278e1a576105d41f502bc40910c100a3dd60d))
* Add method to recalculate prices multiple in grid ([99285c](https://github.com/liquiddesign/eshop/commit/99285cfcd061754d8bea44cefa765c987b5f53c6), [33bf9e](https://github.com/liquiddesign/eshop/commit/33bf9ee6d828a246418452d9726108a715a0bb94))
* Add method to recalculate prices of items in order ([7e41cb](https://github.com/liquiddesign/eshop/commit/7e41cb51c5512dbd394312fdcacbfa50dd62e24d), [3e65dc](https://github.com/liquiddesign/eshop/commit/3e65dc6716f4ed7aa381dae786d1b88d8272e2aa))
* Add multiple csv export of orders in grid to zip ([771d12](https://github.com/liquiddesign/eshop/commit/771d122fcfaec21101ad559e5f5349bb3ae6acfa), [7a0e65](https://github.com/liquiddesign/eshop/commit/7a0e659f5831e26b12e0be902aaf3ac368029880))
* Allow raw url in scripts ([3cab72](https://github.com/liquiddesign/eshop/commit/3cab72e97fb71aa17a50cedf3d2c555830512116), [be1873](https://github.com/liquiddesign/eshop/commit/be187352d1e5064f437ed822f491aa4b75f6202b), [f7ca73](https://github.com/liquiddesign/eshop/commit/f7ca73a0ec0efdfed3663f3debf3cd9260db7a05))
* Better scripts ([ac0275](https://github.com/liquiddesign/eshop/commit/ac027557ed3251fca9712d0db0f1e3766876c665))
* Highlight store amount from set suppliers ([19e9b0](https://github.com/liquiddesign/eshop/commit/19e9b0ea2d448bb89e53b0a346d9b3e63080e23a))
* Order price and visibility lists in groups by priority ([864b2e](https://github.com/liquiddesign/eshop/commit/864b2e6661d209f36e2b704010c6903ec1e4b623))

##### Admin- Customer

* Add getBulkEdits to add option to extend $bulkEdits ([cc7d0c](https://github.com/liquiddesign/eshop/commit/cc7d0c18448855d3c3b791de955a90da2db53765))

##### Admin-order

* Add accunt name to order detail and hover ([4d593a](https://github.com/liquiddesign/eshop/commit/4d593a243b9020784fa1327c01d7c89f7554d920))

##### Checkout Manager

* Inject Integrations ([ae92b0](https://github.com/liquiddesign/eshop/commit/ae92b03b6cedf75f3158cfb184f57c8c53ae38b5))

##### Customer, Account

* Added callbacks and functions to extend columns and filter inputs in grids; ([331f4d](https://github.com/liquiddesign/eshop/commit/331f4dde68fedb6a4ed4a6df0c6c9cc64d5b65d2))

##### Order

* Add option fillProfile, show in order detail ([f7f95f](https://github.com/liquiddesign/eshop/commit/f7f95f638042d5104394626d96864c66f3365058))

##### Pricelist

* Add a description to the price list. ([611042](https://github.com/liquiddesign/eshop/commit/611042b6374567743cdc5012fd2b6dc16085844f))

##### Product Repository

* Make isProductDeliveryFree public, change parameters to optional and set them default value, rewrite isProductDeliveryFree to correctly get discounts ([80a412](https://github.com/liquiddesign/eshop/commit/80a412eafd6a3f6e643e6314d173efaf27396138))

##### Products Cache

* Add ability to update cache indices of selected Customers of CustomerGroups ([93efc1](https://github.com/liquiddesign/eshop/commit/93efc137dc13d7a67e1cbebec58b5174ec358b8f))
* Disable PriceList discounts, better cache update, show index in Customer detail ([1f9264](https://github.com/liquiddesign/eshop/commit/1f926400ccd71901da33af799615007e606031e1))

##### Products Provider

* Dont cache provider results ([57921d](https://github.com/liquiddesign/eshop/commit/57921d3bcad602eb3d503dffe759ef73bfa842aa))

##### Relations

* Add support to filter Related items per shop(s) ([a26d6e](https://github.com/liquiddesign/eshop/commit/a26d6ee59a953639aa6722bb0a325b1aee8a1c71))

### Bug Fixes

* Add default filters to category counts cache from provider ([be547a](https://github.com/liquiddesign/eshop/commit/be547aa395b2d121d98802fda7be4e40c925d8a9))
* Assign correct shops in registration ([8c3c73](https://github.com/liquiddesign/eshop/commit/8c3c73d59a23b1ba7e26ea12bcbc3ba0235b8c54))
* Binder names for OR attributes filtration ([7732a9](https://github.com/liquiddesign/eshop/commit/7732a9183eb9b50c42f6a5ea9f34cf95b7d01c7b))
* Cache ([a92bac](https://github.com/liquiddesign/eshop/commit/a92bacb0758a1efbe147b74a117ba852d2acb4cb), [a935bc](https://github.com/liquiddesign/eshop/commit/a935bcc7bc55f2d1a0ba34248f867c3ffc0f1082))
* Cache pricelist filtering, favourite pricelists ([a15c8e](https://github.com/liquiddesign/eshop/commit/a15c8eadcb25173b5dcd22e66cf3c9de237fb637))
* Category counts ([5e97db](https://github.com/liquiddesign/eshop/commit/5e97db638bc909643879ca9c1e8bf7ea1fcec12e))
* Check if pricelist assigned discount is same as coupon applied in cart to apply pricelist ([071b61](https://github.com/liquiddesign/eshop/commit/071b61aa676693b77f14601b4a26e9110e2d46fd), [1faae1](https://github.com/liquiddesign/eshop/commit/1faae155d3d0d739131c6fc1f548e0c6aefb1e0e))
* Comgate ([b0fd8d](https://github.com/liquiddesign/eshop/commit/b0fd8d5b0bdd2f5747411fc0dfe4eb1c0ff2cc0a))
* Config wrong type ([8c7ecf](https://github.com/liquiddesign/eshop/commit/8c7ecff337440c7494b7bfe6e5955f797c81b864))
* Content selection ([57b265](https://github.com/liquiddesign/eshop/commit/57b2655d4beb71bb300f9d9193ddaae2ac665a7e))
* Correct filter of categories in product admin grid ([03dd50](https://github.com/liquiddesign/eshop/commit/03dd5000eb3a631783cd3bd39a0767e11ed057b2))
* Delivery combination rules ([ebf0e5](https://github.com/liquiddesign/eshop/commit/ebf0e584640dfeea4e024b0cb9ddcbcdfeae7452))
* Delivery combination validation ([18128c](https://github.com/liquiddesign/eshop/commit/18128c6188ccbc98f2144e2fc356369d0a7ec6ac))
* Discount coupon conditions ([946476](https://github.com/liquiddesign/eshop/commit/9464764fc4cb23b66b53dc45eebdab2f0b817cf4))
* Dont enforce joining product content in get products, only select ([2732f4](https://github.com/liquiddesign/eshop/commit/2732f413f6d35ede90f43f3c0a9a3ff2843c128b))
* Feeds typo fix ([0ca78d](https://github.com/liquiddesign/eshop/commit/0ca78da28329520c1857d5d6e105ae4c72b48b1c))
* Find customer by shop in order creation ([c2f871](https://github.com/liquiddesign/eshop/commit/c2f87108282be369a1f4bf4722cd53e1036e9335))
* GetProductPricesFormattedFromCartItem bad multiply remove ([d0034e](https://github.com/liquiddesign/eshop/commit/d0034ef2774225860c58e217a3822b662ac86d05))
* GetRealCart() crashing on null value ([12c411](https://github.com/liquiddesign/eshop/commit/12c4115578ba77f71d30e1eed55a5930c513e52f))
* Handle missing prices in cache warmup ([773b5a](https://github.com/liquiddesign/eshop/commit/773b5a196a6d09dec453cd2f69ee2aaa1d872e68))
* Hide bad column ([098998](https://github.com/liquiddesign/eshop/commit/098998a1af087b52155a2f31cef770f1d70cd3de))
* Hide some filters ([c654ef](https://github.com/liquiddesign/eshop/commit/c654ef835bfca9642162e4ba98cf1392febef69a))
* Item count of PriceListItems grid ([a8183c](https://github.com/liquiddesign/eshop/commit/a8183c0d06b8933bcee3f9867b09624631b4cc3a), [480da2](https://github.com/liquiddesign/eshop/commit/480da28d3d9ad5ec16fb40ea7a09f31eabbf776a))
* Order detail ([d2d681](https://github.com/liquiddesign/eshop/commit/d2d6812b6a145fb1da4fc61525ba4de0642fb447))
* Php 8.3 compatibility ([f4b927](https://github.com/liquiddesign/eshop/commit/f4b9270cee657b02b99d43392a6e324460c2f43e))
* Preload items of productlist in render method to properly load paginator in advance ([58df6a](https://github.com/liquiddesign/eshop/commit/58df6ab6b28cd94dd672b93e64db08d1c23735fb))
* Price filters validation ([f24282](https://github.com/liquiddesign/eshop/commit/f242825ea839ca1be40709b46acbc4e735a43728))
* ProductFilter::getSystemicAttributeValues - rewrite sql for producers for better performance ([94a58b](https://github.com/liquiddesign/eshop/commit/94a58be23d69347d1a40e08ef68753f6c3346a1e))
* Products provider - order price correctly to new format ([ded586](https://github.com/liquiddesign/eshop/commit/ded586809e84ff3fa0741ea0c7b03f6f421e20c8))
* ProductsProvider changes ([d2ec9e](https://github.com/liquiddesign/eshop/commit/d2ec9eadfbeadbc1cdea39642671bc7cd1fe3f51))
* Require attribute in filters, add zasilkovna setting ([16e0be](https://github.com/liquiddesign/eshop/commit/16e0be49ea6d5bb08a0bb23543107b16e25ad0b0))
* Rounding of discountPercent ([ea5360](https://github.com/liquiddesign/eshop/commit/ea5360c200e8ee503118a3095a9645fca78f5f1f))
* Showbar ([31162a](https://github.com/liquiddesign/eshop/commit/31162aea84ce9e187816f028d7037fdf0cba88a9))
* Show product form errors ([10b523](https://github.com/liquiddesign/eshop/commit/10b5235db4a49deebef2bf48343fb50d91572e8d))
* Type fix ([05ad24](https://github.com/liquiddesign/eshop/commit/05ad243ea4e220a42f2421932fe2c126975dc06b))
* Types in grouping, registration config ([25299e](https://github.com/liquiddesign/eshop/commit/25299ed43c819859533c9eeacf2c9f7c628a0083))
* Typo fix ([c8c8a2](https://github.com/liquiddesign/eshop/commit/c8c8a26769058712928713d3342b8999b56661ff))
* Various ([171ec0](https://github.com/liquiddesign/eshop/commit/171ec0f20f1e17836c7fffbca1313b179ad5f8ca))
* ZEND_FETCH_DIM_W ([026d91](https://github.com/liquiddesign/eshop/commit/026d914a80806d75d9d1a1243299bbaecbe1198d))

##### Admin

* Recalculate order items prices with high precision ([1b1dc5](https://github.com/liquiddesign/eshop/commit/1b1dc52658229cbc52c2cf01c2241af249021552))
* Show correct ancestor categories in detail ([b405a3](https://github.com/liquiddesign/eshop/commit/b405a3c60f38f2d46b34e227db6be764e9d19f0a))
* Show exportCsv and exportEdi buttons only if configuration allows it ([04ec40](https://github.com/liquiddesign/eshop/commit/04ec400c71c044a187c33cde921040d4cfcd8fef), [e3c86a](https://github.com/liquiddesign/eshop/commit/e3c86aeecbf905b1a1a1278b215eab52828af2bf))

##### Cache

* Improvements in cache cleaning ([c3fc55](https://github.com/liquiddesign/eshop/commit/c3fc55ca32f8a558b44734a27ea12b77bee62622))

##### Checkout Manager:create Customer

* Assign visibility lists to new customer from customer group ([c2b4e4](https://github.com/liquiddesign/eshop/commit/c2b4e499ffd70c74dbb0ef0aa353d5a792203132))

##### Comgate

* Round price in payment correctly ([c4691e](https://github.com/liquiddesign/eshop/commit/c4691ea174a15b8f65190faf1e49249dba652ec0), [57cab1](https://github.com/liquiddesign/eshop/commit/57cab1919ce51c5f01fc0eef0cb221bc7772c14a))

##### Dpd

* Increase soap client timeout ([174c1f](https://github.com/liquiddesign/eshop/commit/174c1f12410d83247667a1865dfced5a201720b5))

##### Invoice

* Constraints ([44b741](https://github.com/liquiddesign/eshop/commit/44b7418b6ea624eaec3feec26976e1d40f1fe813))

##### Order Edit Service

* Adding products fixed ([752bc4](https://github.com/liquiddesign/eshop/commit/752bc4ce74d7fbb33963bae3d5425decc0b04a60))

##### Products Provider

* Throw exception if related filter values are invalid instead of warning ([d41d7d](https://github.com/liquiddesign/eshop/commit/d41d7d19d9593b90a470c40681f435f36f0dbcbe), [bdc6a9](https://github.com/liquiddesign/eshop/commit/bdc6a9ddc10cda6da39b02b6bc60faf2e806af19))

##### Relations

* New import ([c8b785](https://github.com/liquiddesign/eshop/commit/c8b785ce153b199b44bec7625a7575801dcb782a))


---

# Ⓔ Eshop - CHANGELOG

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
- **BREAKING:** `Product::toArray` now accepts `$shop` and `$selectContent` parameters. `$selectContent` defaults to true, so it selects content, even if it is not loaded. That can lead to performance issue, so always check how you use this.
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

- **BREAKING:** When changing amount of item in `CartItemList` and new value is <=0, value was changed to 1. Now, instead of changing value, item  is deleted from cart.
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
