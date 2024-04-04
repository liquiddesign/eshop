<?php

declare(strict_types=1);

namespace Eshop\DB;

use Nette\Utils\Random;
use StORM\DIConnection;
use StORM\SchemaManager;

/**
 * @extends \StORM\Repository<\Eshop\DB\Complaint>
 */
class ComplaintRepository extends \StORM\Repository
{
	public string $complaintCodePrefix = 'R';

	/**
	 * @var null|callable(): string Occurs on complaint code generation
	 */
	public $onGenerateComplaintCode = null;

	private OrderRepository $orderRepository;

	private ComplaintStateRepository $complaintStateRepository;

	public function __construct(
		DIConnection $connection,
		SchemaManager $schemaManager,
		OrderRepository $orderRepository,
		ComplaintStateRepository $complaintStateRepository
	) {
		parent::__construct($connection, $schemaManager);

		$this->orderRepository = $orderRepository;
		$this->complaintStateRepository = $complaintStateRepository;
	}

	/**
	 * @param array<mixed> $values
	 */
	public function create(array $values): Complaint
	{
		if (!isset($values['code'])) {
			$values['code'] = $this->generateComplaintCode();
		}

		if (!isset($values['order']) && isset($values['orderCode'])) {
			$order = $this->orderRepository->one(['code' => $values['orderCode']]);

			if ($order) {
				$values['order'] = $order->getPK();
			}
		}

		if (isset($values['order'])) {
			$order = $this->orderRepository->one($values['order'] instanceof Order ? $values['order']->getPK() : $values['order']);

			$customer = $order->purchase->customer;

			$values['customerFullName'] ??= $customer ? $customer->fullname : $order->purchase->fullname;
			$values['customerEmail'] ??= $customer ? $customer->email : $order->purchase->email;
			$values['customerPhone'] ??= $customer ? $customer->phone : $order->purchase->phone;

			$values['customer'] ??= $customer ? $customer->getPK() : null;
		}

		$values['complaintState'] ??= $this->complaintStateRepository->getCollection()->first();

		return $this->createOne($values);
	}

	public function generateComplaintCode(): string
	{
		do {
			$code = isset($this->onGenerateComplaintCode) ? \call_user_func($this->onGenerateComplaintCode) : $this->complaintCodePrefix . Random::generate(8, '0-9');

			$existingComplaint = $this->one(['code' => $code]);
		} while ($existingComplaint);

		return $code;
	}
}
