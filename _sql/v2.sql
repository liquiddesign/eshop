ALTER TABLE eshop_product DROP COLUMN hidden;
ALTER TABLE eshop_product DROP COLUMN hiddenInMenu;
ALTER TABLE eshop_product DROP COLUMN unavailable;
ALTER TABLE eshop_product DROP COLUMN priority;
ALTER TABLE eshop_product DROP COLUMN recommended;

ALTER TABLE eshop_product DROP FOREIGN KEY eshop_product_primaryCategory;
ALTER TABLE eshop_product DROP COLUMN fk_primaryCategory;

DROP TABLE eshop_parametervalue;
DROP TABLE eshop_parameteravailablevalue;
DROP TABLE eshop_parametergroup;
DROP TABLE eshop_parametercategory;
DROP TABLE eshop_parameter;

DROP TABLE eshop_producttabtext;
DROP TABLE eshop_producttab;

DROP TABLE eshop_setitem;
DROP TABLE eshop_set;

DROP TABLE eshop_product_nxn_eshop_tag;
DROP TABLE eshop_tag_nxn_eshop_tag;
DROP TABLE eshop_tag;