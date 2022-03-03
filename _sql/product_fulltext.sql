ALTER TABLE `eshop_product`
ADD FULLTEXT `name_cs_perex_cs_content_cs` (`name_cs`, `perex_cs`, `content_cs`);

ALTER TABLE `eshop_product`
ADD FULLTEXT `name_en_perex_en_content_en` (`name_en`, `perex_en`, `content_en`);

ALTER TABLE `eshop_product`
ADD FULLTEXT `name_cs` (`name_cs`);

ALTER TABLE `eshop_product`
ADD FULLTEXT `name_en` (`name_en`);