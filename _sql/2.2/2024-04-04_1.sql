-- @TODO Fill all your mutations and shops

INSERT INTO eshop_productcontent (fk_product, name_cs, fk_shop)
SELECT uuid, name_cs, 'abel' AS fk_shop
FROM eshop_product
WHERE name_cs IS NOT NULL
ON DUPLICATE KEY UPDATE name_cs = VALUES(name_cs);

INSERT INTO eshop_productcontent (fk_product, name_cs, fk_shop)
SELECT uuid, name_cs, 'rt' AS fk_shop
FROM eshop_product
WHERE name_cs IS NOT NULL
ON DUPLICATE KEY UPDATE name_cs = VALUES(name_cs);