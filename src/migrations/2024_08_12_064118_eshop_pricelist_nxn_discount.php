<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
	public function up(): void
	{
		Schema::table('eshop_pricelist', function (Blueprint $table): void {
			$table->dropForeign('eshop_pricelist_discount');
			$table->dropIndex('eshop_pricelist_discount');
			$table->dropColumn('fk_discount');
		});
	}
};
