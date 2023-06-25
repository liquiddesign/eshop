# Up
ALTER TABLE eshop_product ADD id BIGINT UNSIGNED unique key;
SET @row_number = 0;
UPDATE eshop_product SET id = (@row_number:=@row_number + 1);
ALTER TABLE eshop_product MODIFY COLUMN id bigint(20) unsigned auto_increment NOT NULL;

# Down - dont execute on creation!
ALTER TABLE eshop_product DROP KEY id, DROP COLUMN id;