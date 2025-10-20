<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
	public function up(): void
	{
		$vatRates = DB::table('eshop_vatrate')
			->get()
			->groupBy('fk_country')
			->map(fn($group) => $group->keyBy('uuid'));

		foreach (DB::table('eshop_product')->get() as $product) {
			$vatRate = $vatRates['CZ'][$product->vatRate] ?? null;

			if ($vatRate === null) {
				continue;
			}

			DB::table('eshop_productvatrate')->upsert([
				'uuid' => Str::replace('-', '', Str::uuid7()),
				'fk_product' => $product->uuid,
				'fk_vatRate' => $vatRate->uuid,
				'fk_country' => 'CZ',
			], ['fk_product', 'fk_country', 'fk_vatRate', 'uuid'], ['fk_vatRate']);
		}

		Schema::table('eshop_product', function (Blueprint $table): void {
			$table->dropColumn('vatRate');
		});
	}
};
