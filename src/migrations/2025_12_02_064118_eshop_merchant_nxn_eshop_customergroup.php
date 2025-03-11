<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
	public function up(): void
	{
		DB::table('eshop_merchant_nxn_eshop_customergroup')->insertUsing(
			['fk_merchant', 'fk_customerGroup'],
			DB::table('eshop_merchant')->select('uuid', 'fk_customerGroup')->whereNotNull('fk_customerGroup')
		);

		Schema::table('eshop_merchant', function (Blueprint $table): void {
			$table->dropForeign('eshop_merchant_customerGroup');
			$table->dropColumn('fk_customerGroup');
		});
	}
};
