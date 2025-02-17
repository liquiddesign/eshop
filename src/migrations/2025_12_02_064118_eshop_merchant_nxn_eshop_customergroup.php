<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
	public function up(): void
	{
		Schema::create('eshop_merchant_nxn_eshop_customergroup', function (Blueprint $table): void {
			$table->foreignUuid('fk_merchant')->references('uuid')->on('eshop_merchant')->onDelete('cascade')->onUpdate('cascade');
			$table->foreignUuid('fk_customerGroup')->references('uuid')->on('eshop_customergroup')->onDelete('cascade')->onUpdate('cascade');
		});

		DB::table('eshop_merchant_nxn_eshop_customergroup')->insertUsing(
			['fk_merchant', 'fk_customerGroup'],
			DB::table('eshop_merchant')->select('uuid', 'fk_customerGroup')
		);

		Schema::table('eshop_merchant', function (Blueprint $table): void {
			$table->dropForeign('eshop_merchant_customerGroup');
			$table->dropColumn('fk_customerGroup');
		});
	}

	public function down(): void
	{
		Schema::table('eshop_merchant', function (Blueprint $table): void {
			$table->foreignUuid('fk_customerGroup')->nullable();
		});

		DB::table('eshop_merchant')->updateOrInsert(
			['uuid' => DB::raw('eshop_merchant_nxn_eshop_customergroup.fk_merchant')],
			['fk_customerGroup' => DB::raw('eshop_merchant_nxn_eshop_customergroup.fk_customerGroup')]
		);

		Schema::table('eshop_merchant', function (Blueprint $table): void {
			$table->foreign('fk_customerGroup')->references('uuid')->on('eshop_customergroup')->onDelete('cascade')->onUpdate('cascade');
		});

		Schema::dropIfExists('eshop_merchant_nxn_eshop_customergroup');
	}
};
