ALTER TABLE eshop_attributevalue ADD COLUMN id INT UNSIGNED NOT NULL auto_increment, ADD UNIQUE INDEX (id);
ALTER TABLE eshop_product ADD COLUMN id INT UNSIGNED NOT NULL auto_increment, ADD UNIQUE INDEX (id);
ALTER TABLE eshop_producer ADD COLUMN id INT UNSIGNED NOT NULL auto_increment, ADD UNIQUE INDEX (id);
ALTER TABLE eshop_displayamount ADD COLUMN id INT UNSIGNED NOT NULL auto_increment, ADD UNIQUE INDEX (id);
ALTER TABLE eshop_category ADD COLUMN id INT UNSIGNED NOT NULL auto_increment, ADD UNIQUE INDEX (id);
ALTER TABLE eshop_pricelist ADD COLUMN id INT UNSIGNED NOT NULL auto_increment, ADD UNIQUE INDEX (id);
ALTER TABLE eshop_visibilitylist ADD COLUMN id INT UNSIGNED NOT NULL auto_increment, ADD UNIQUE INDEX (id);
ALTER TABLE eshop_displaydelivery ADD COLUMN id INT UNSIGNED NOT NULL auto_increment, ADD UNIQUE INDEX (id);
ALTER TABLE eshop_attribute ADD COLUMN id INT UNSIGNED NOT NULL auto_increment, ADD UNIQUE INDEX (id);

INSERT INTO `eshop_productscachestate` (`uuid`, `state`) VALUES ('1', 'empty');
INSERT INTO `eshop_productscachestate` (`uuid`, `state`) VALUES ('2', 'empty');