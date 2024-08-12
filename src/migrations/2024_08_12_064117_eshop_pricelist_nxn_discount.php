<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
	public function up(): void
	{
		\Illuminate\Support\Facades\DB::unprepared('INSERT INTO eshop_discount_nxn_eshop_pricelist (fk_pricelist, fk_discount)
SELECT uuid, fk_discount FROM eshop_pricelist WHERE eshop_pricelist.fk_discount IS NOT NULL;');
	}
};
