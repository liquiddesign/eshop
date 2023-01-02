<?php

namespace Eshop\Services;

use Carbon\Carbon;
use Defr\QRPlatba\QRPlatba;
use Eshop\Admin\SettingsPresenter;
use Eshop\DB\Invoice;
use Eshop\DB\Order;
use Web\DB\SettingRepository;

class QrPaymentGenerator
{
	private SettingRepository $settingRepository;
	public function __construct(SettingRepository $settingRepository)
	{
		$this->settingRepository = $settingRepository;
	}

	public function generateQrPaymentObjectForOrder(Order $order, bool $checkPaymentType = true): QRPlatba
	{
		if ($checkPaymentType) {
			$bankPaymentType = $this->settingRepository->getValueByName(SettingsPresenter::BANK_PAYMENT_TYPE);

			if (!$bankPaymentType) {
				throw new \Exception('No bank payment type specified in settings');
			}

			if ($order->purchase->getValue('paymentType') !== $bankPaymentType) {
				throw new \Exception('Order has different payment type than bank payment type');
			}
		}

		$bankAccountNumber = $this->settingRepository->getValueByName(SettingsPresenter::BANK_ACCOUNT_NUMBER);
		$bankIBAN = $this->settingRepository->getValueByName(SettingsPresenter::BANK_IBAN);

		if (!$bankAccountNumber) {
			throw new \Exception('Bank account number must be set');
		}

		$qrPayment = new QRPlatba();

		$qrPayment->setAccount($bankAccountNumber);

		if ($bankIBAN) {
			$qrPayment->setIBAN($bankIBAN);
		}

		$qrPayment->setVariableSymbol($order->code)
			->setAmount($order->getTotalPriceVat())
			->setCurrency($order->purchase->currency->code)
			->setDueDate(Carbon::now());

		return $qrPayment;
	}

	/**
	 * @throws \Exception
	 */
	public function generateQrPaymentForOrder(Order $order, bool $checkPaymentType = true): string
	{
		return $this->generateQrPaymentObjectForOrder($order, $checkPaymentType)->getQRCodeImage();
	}

	public function generateQrPaymentForInvoice(Invoice $invoice, bool $checkPaymentType = true): string
	{
		if ($checkPaymentType) {
			$bankPaymentType = $this->settingRepository->getValueByName(SettingsPresenter::BANK_PAYMENT_TYPE);

			if (!$bankPaymentType) {
				throw new \Exception('No bank payment type specified in settings');
			}

			$order = $invoice->orders->first();

			if (($order->purchase->getValue('paymentType') !== $bankPaymentType) && $invoice->getValue('paymentType') !== $bankPaymentType) {
				throw new \Exception('Invoice has different payment type than bank payment type');
			}
		}

		$bankAccountNumber = $this->settingRepository->getValueByName(SettingsPresenter::BANK_ACCOUNT_NUMBER);
		$bankIBAN = $this->settingRepository->getValueByName(SettingsPresenter::BANK_IBAN);

		if (!$bankAccountNumber) {
			throw new \Exception('Bank account number must be set');
		}

		$qrPayment = new QRPlatba();

		$qrPayment->setAccount($bankAccountNumber);

		if ($bankIBAN) {
			$qrPayment->setIBAN($bankIBAN);
		}

		$qrPayment->setVariableSymbol($invoice->code)
			->setAmount($invoice->getTotalPriceVat())
			->setCurrency($invoice->currency->code)
			->setDueDate(Carbon::now());

		return $qrPayment->getQRCodeImage(true, 150);
	}
}
