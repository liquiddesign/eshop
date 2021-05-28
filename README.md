# Ⓔ Eshop
Služby, entity, administrace a kontroly pro eshop
 
![Travis](https://travis-ci.org/liquiddesign/eshop.svg?branch=master)
![Release](https://img.shields.io/github/v/release/liquiddesign/eshop.svg?1)

## Dokumentace
☞ [Dropbox paper](https://paper.dropbox.com/doc/E-Eshop--BGZLihaxZHQ3iGcTOQkPYfXrAg-eOMqwxUnWnQGWEGWGxnHl)

## TODO

##Cache
Všechny statistiky mají dependency tag "stats".
Výpis pickup points má tag "pickupPoints".

// 1. selected current category ROOT PATH example: (abc0ddd1) -> abc0
// 2. DO THIS

SELECT * atributes
JOIN category_attribute_nxn as nxn ON nxn.fk_attribute = this.uuid
JOIN categories as categories ON categories.path LIKE 'abc0%' AND categories.uuid=nxn.fk_category

WHERE categories.uuid IS NOT NULL