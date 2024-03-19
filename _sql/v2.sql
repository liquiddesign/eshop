ALTER TABLE eshop_product DROP COLUMN hidden;
ALTER TABLE eshop_product DROP COLUMN hiddenInMenu;
ALTER TABLE eshop_product DROP COLUMN unavailable;
ALTER TABLE eshop_product DROP COLUMN priority;
ALTER TABLE eshop_product DROP COLUMN recommended;

ALTER TABLE eshop_product DROP FOREIGN KEY eshop_product_primaryCategory;
ALTER TABLE eshop_product DROP COLUMN fk_primaryCategory;

TRUNCATE TABLE eshop_supplierparametervalue;
DROP TABLE eshop_supplierparametervalue;

TRUNCATE TABLE eshop_parametervalue;
DROP TABLE eshop_parametervalue;
TRUNCATE TABLE eshop_parameteravailablevalue;
DROP TABLE eshop_parameteravailablevalue;
TRUNCATE TABLE eshop_parameter;
DROP TABLE eshop_parameter;
TRUNCATE TABLE eshop_product_nxn_eshop_parametergroup;
DROP TABLE eshop_product_nxn_eshop_parametergroup;
TRUNCATE TABLE eshop_parametergroup;
DROP TABLE eshop_parametergroup;
TRUNCATE TABLE eshop_category_nxn_eshop_parametercategory;
DROP TABLE eshop_category_nxn_eshop_parametercategory;

ALTER TABLE eshop_category DROP FOREIGN KEY eshop_category_parameterCategory;
ALTER TABLE eshop_category DROP COLUMN fk_parameterCategory;
ALTER TABLE eshop_suppliercategory DROP FOREIGN KEY eshop_suppliercategory_parameterCategory;
ALTER TABLE eshop_suppliercategory DROP COLUMN fk_parameterCategory;

TRUNCATE TABLE eshop_parametercategory;
DROP TABLE eshop_parametercategory;

TRUNCATE TABLE eshop_producttabtext;
DROP TABLE eshop_producttabtext;
TRUNCATE TABLE eshop_producttab;
DROP TABLE eshop_producttab;

TRUNCATE TABLE eshop_setitem;
DROP TABLE eshop_setitem;
TRUNCATE TABLE eshop_set;
DROP TABLE eshop_set;

TRUNCATE TABLE eshop_product_nxn_eshop_tag;
DROP TABLE eshop_product_nxn_eshop_tag;
TRUNCATE TABLE eshop_tag_nxn_eshop_tag;
DROP TABLE eshop_tag_nxn_eshop_tag;
TRUNCATE TABLE eshop_discount_nxn_eshop_tag;
DROP TABLE eshop_discount_nxn_eshop_tag;
TRUNCATE TABLE eshop_tag;
DROP TABLE eshop_tag;

ALTER TABLE eshop_productcontent
    ADD FULLTEXT `content_cs` (`content_cs`);

ALTER TABLE eshop_productcontent
    ADD FULLTEXT `perex_cs` (`perex_cs`);

ALTER TABLE eshop_productcontent
    ADD FULLTEXT `name_content_cs` (`content_cs`, `perex_cs`);

UPDATE messages_template SET code = uuid;
