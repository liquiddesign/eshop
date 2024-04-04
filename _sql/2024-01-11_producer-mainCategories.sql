INSERT IGNORE INTO eshop_producer_nxn_eshop_category (fk_producer, fk_category)
SELECT uuid AS fk_producer, fk_mainCategory AS fk_category
FROM eshop_producer
where fk_mainCategory is not null;