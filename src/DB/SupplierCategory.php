<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Mapování kategorií
 * @table
 * @index{"name":"supplier_category_name","unique":true,"columns":["categoryNameL1","categoryNameL2","categoryNameL3","categoryNameL4","fk_supplier"]}
 */
class SupplierCategory extends \StORM\Entity
{
	/**
	 * Kód
	 * @column
	 */
	public ?string $code;
	
	/**
	 * Vzor na mapování 1
	 * @column{"length":128}
	 */
	public string $categoryNameL1;
	
	/**
	 * Vzor na mapování 2
	 * @column{"length":128}
	 */
	public ?string $categoryNameL2;
	
	/**
	 * Vzor na mapování 3
	 * @column{"length":128}
	 */
	public ?string $categoryNameL3;
	
	/**
	 * Vzor na mapování 3
	 * @column{"length":128}
	 */
	public ?string $categoryNameL4;
	
	/**
	 * Mapování kategorií, jestli je zadáno
	 * @relation
	 * @constraint{"onUpdate":"SET NULL","onDelete":"SET NULL"}
	 */
	public ?ParameterCategory $parameterCategory;
	
	/**
	 * Mapování kategorií, jestli je zadáno
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 */
	public ?Category $category;
	
	/**
	 * Dodavatel
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public Supplier $supplier;
	
	/**
	 * Aktualizován
	 * @column{"type":"timestamp","default":"CURRENT_TIMESTAMP","extra":"on update CURRENT_TIMESTAMP"}
	 */
	public string $updateTs;
	
	/**
	 * Vytvořen
	 * @column{"type":"timestamp","default":"CURRENT_TIMESTAMP"}
	 */
	public string $createdTs;
	
	public function getNameTree(string $glue = ' > '): string
	{
		$str = $this->categoryNameL1;
		
		if ($this->categoryNameL2) {
			$str .= $glue . $this->categoryNameL2;
		}
		
		if ($this->categoryNameL3) {
			$str .= $glue . $this->categoryNameL3;
		}
		
		if ($this->categoryNameL4) {
			$str .= $glue . $this->categoryNameL4;
		}
		
		return $str;
	}
}
