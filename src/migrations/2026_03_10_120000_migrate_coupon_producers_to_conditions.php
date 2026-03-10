<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
	/**
	 * Migrace dat z primo vazane N:N tabulky eshop_discountcoupon_nxn_producer
	 * do noveho podminkoveho systemu (eshop_discountconditionproducer + eshop_discountconditionproducer_nxn_eshop_producer)
	 */
	public function up(): void
	{
		if (!Schema::hasTable('eshop_discountcoupon_nxn_producer')) {
			return;
		}

		// Ziskani vsech kuponu, ktere maji prirazene producenty
		$couponProducers = DB::table('eshop_discountcoupon_nxn_producer')
			->select('fk_discountcoupon')
			->distinct()
			->get();

		foreach ($couponProducers as $row) {
			$conditionUuid = str_replace('-', '', (string) Str::uuid());

			// Vytvoreni podminky pro vyrobce
			DB::table('eshop_discountconditionproducer')->insert([
				'uuid' => $conditionUuid,
				'cartCondition' => 'isInCart',
				'quantityCondition' => 'all',
				'fk_discountCoupon' => $row->fk_discountcoupon,
				'fk_deliveryDiscount' => null,
				'fk_apiGeneratorDiscountCoupon' => null,
			]);

			// Preneseni vazeb na producenty
			$producers = DB::table('eshop_discountcoupon_nxn_producer')
				->where('fk_discountcoupon', $row->fk_discountcoupon)
				->get();

			foreach ($producers as $producer) {
				DB::table('eshop_discountconditionproducer_nxn_eshop_producer')->insert([
					'fk_discountconditionproducer' => $conditionUuid,
					'fk_producer' => $producer->fk_producer,
				]);
			}
		}

		// Smazani stare N:N tabulky
		Schema::dropIfExists('eshop_discountcoupon_nxn_producer');
	}

	/**
	 * Reverse the migration.
	 */
	public function down(): void
	{
		// Obnoveni stare tabulky
		if (!Schema::hasTable('eshop_discountcoupon_nxn_producer')) {
			Schema::create('eshop_discountcoupon_nxn_producer', function ($table): void {
				$table->string('fk_discountcoupon', 36);
				$table->string('fk_producer', 36);

				$table->primary(['fk_discountcoupon', 'fk_producer']);
				$table->foreign('fk_discountcoupon')->references('uuid')->on('eshop_discount_coupon')->onDelete('cascade');
				$table->foreign('fk_producer')->references('uuid')->on('eshop_producer')->onDelete('cascade');
			});
		}
	}
};
