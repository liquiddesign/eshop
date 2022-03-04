<?php

namespace Eshop;

use Eshop\DB\ApiGeneratorDiscountCouponRepository;
use Eshop\DB\DiscountConditionRepository;
use Eshop\DB\DiscountCouponRepository;
use Nette\Application\UI\Presenter;
use Nette\Utils\Random;
use Nette\Utils\Strings;

abstract class ApiGeneratorPresenter extends Presenter
{
	/** @inject */
	public ApiGeneratorDiscountCouponRepository $apiGeneratorDiscountCouponRepository;

	/** @inject */
	public DiscountCouponRepository $discountCouponRepository;

	/** @inject */
	public DiscountConditionRepository $discountConditionRepository;

	public function actionDefault(string $generator, string $code): void
	{
		$result = false;
		$error = null;

		try {
			$generatorName = Strings::firstUpper(Strings::lower($generator));
			$methodName = "generate$generatorName";

			$reflection = new \ReflectionClass(self::class);

			if (!$reflection->hasMethod($methodName)) {
				throw new \Exception("Generator '$generatorName' not found");
			}

			$method = $reflection->getMethod($methodName);

			if ($method->getNumberOfRequiredParameters() !== 1) {
				throw new \Exception('Internal generator error');
			}

			$result = $this->$methodName($code);
		} catch (\Throwable $e) {
			$error = $e->getMessage();
		}

		$this->sendJson($result ? [
			'status' => 'success',
			'result' => $result,
		] : [
			'status' => 'error',
			'message' => $error,
		]);
	}

	/**
	 * @param string $code
	 * @return array<string>|null
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function generateDiscountCoupon(string $code): ?array
	{
		$apiGeneratorDiscountCoupon = $this->apiGeneratorDiscountCouponRepository->one(['code' => $code]);

		if (!$apiGeneratorDiscountCoupon || !$apiGeneratorDiscountCoupon->isActive()) {
			throw new \Exception('Invalid code');
		}

		do {
			$newCouponFormCode = $apiGeneratorDiscountCoupon->code . '-' . Random::generate(10, '0-9A-Z');
			$temp = $this->discountCouponRepository->many()
				->where('code', $newCouponFormCode)
				->where('fk_discount', $apiGeneratorDiscountCoupon->discount->getPK())
				->first();
		} while ($temp);

		$newDiscountCoupon = $this->discountCouponRepository->createOne([
			'code' => $newCouponFormCode,
			'label' => "ApiGenerated: $apiGeneratorDiscountCoupon->label",
			'discountValue' => $apiGeneratorDiscountCoupon->discountValue,
			'discountValueVat' => $apiGeneratorDiscountCoupon->discountValueVat,
			'discountPct' => $apiGeneratorDiscountCoupon->discountPct,
			'usageLimit' => $apiGeneratorDiscountCoupon->usageLimit,
			'minimalOrderPrice' => $apiGeneratorDiscountCoupon->minimalOrderPrice,
			'maximalOrderPrice' => $apiGeneratorDiscountCoupon->maximalOrderPrice,
			'conditionsType' => $apiGeneratorDiscountCoupon->conditionsType,
			'exclusiveCustomer' => $apiGeneratorDiscountCoupon->exclusiveCustomer,
			'discount' => $apiGeneratorDiscountCoupon->discount,
			'currency' => $apiGeneratorDiscountCoupon->currency,
		]);

		foreach ($this->discountConditionRepository->many()->where('fk_apiGeneratorDiscountCoupon', $apiGeneratorDiscountCoupon->getPK()) as $condition) {
			$newValues = $condition->toArray(['products']);

			unset($newValues['uuid']);
			$newValues['apiGeneratorDiscountCoupon'] = null;
			$newValues['discountCoupon'] = $newDiscountCoupon->getPK();

			$this->discountConditionRepository->createOne($newValues);
		}

		$apiGeneratorDiscountCoupon->used();

		return [
			'code' => $newDiscountCoupon->code,
		];
	}
}
