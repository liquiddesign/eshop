<?php

namespace Eshop\Admin\HelperClasses;

use Eshop\DB\File;
use Eshop\DB\Photo;
use Eshop\DB\Product;
use Nette\DI\Container;
use Nette\Utils\Arrays;
use Nette\Utils\FileSystem;
use Nette\Utils\Image;
use Ramsey\Uuid\Uuid;
use StORM\DIConnection;
use StORM\Entity;

readonly class ProductCloner
{
	private const INVERSE_RELATION_KEYS = [
		'contents',
		'supplierProducts',
		'variants',
		'quantityPrices',
		'taxes',
		'loyaltyPrograms',
		'reviews',
		'visibilityListItems',
	];

	private const NXN_RELATION_KEYS = [
		'categories',
		'internalRibbons',
		'ribbons',
	];

	public function __construct(private Container $container, private \PDO $link,)
	{
	}

	/**
	 * @param \Eshop\DB\Product $sourceProduct
	 * @param array<\Eshop\DB\Product> $targetProducts
	 * @param array<string> $clonedFields
	 */
	public function cloneProduct(Product $sourceProduct, array $targetProducts, array $clonedFields): void
	{
		$this->link->beginTransaction();

		foreach ($targetProducts as $targetProduct) {
			foreach ($clonedFields as $clonedField) {
				if ($clonedField === 'files') {
					$this->cloneFilesRelation($sourceProduct, $targetProduct);
				} elseif ($clonedField === 'photos' || $clonedField === 'galleryImages') {
					$this->clonePhotosRelation($sourceProduct, $targetProduct, $clonedField);
				} elseif (Arrays::contains(self::INVERSE_RELATION_KEYS, $clonedField)) {
					$this->cloneSimpleInverseRelation($sourceProduct, $targetProduct, $clonedField);
				} elseif (Arrays::contains(self::NXN_RELATION_KEYS, $clonedField)) {
					$this->cloneNxNRelation($sourceProduct, $targetProduct, $clonedField);
				} else {
					$targetProduct->{$clonedField} = $sourceProduct->{$clonedField};
				}
			}

			$targetProduct->updateAll();
		}

		$this->link->commit();
	}

	private function cloneSimpleInverseRelation(Product $sourceProduct, Product $targetProduct, string $relationName): void
	{
		/** @var \StORM\RelationCollection $sourceRelationValue */
		$sourceRelationValue = $sourceProduct->{$relationName};
		$relationRepository = $sourceRelationValue->getRepository();

		$targetProduct->{$relationName}->delete();

		foreach ($sourceRelationValue as $relation) {
			$relationArray = $relation->toArray(includePK: false);
			unset($relationArray['product']);
			$relationRepository->createOne($relationArray, ignore: true);
		}
	}

	private function cloneNxNRelation(Product $sourceProduct, Product $targetProduct, string $relationName): void
	{
		/** @var \StORM\RelationCollection $sourceRelationValue */
		$sourceRelationValue = $sourceProduct->{$relationName};
		$newValues = \array_map(function (Entity $entity) {
			return $entity->getPK();
		}, $sourceRelationValue->toArray());

		$targetProduct->{$relationName}->unrelateAll();

		if (\count($newValues) === 0) {
			return;
		}

		$targetProduct->{$relationName}->relate($newValues);
	}

	private function cloneFilesRelation(Product $sourceProduct, Product $targetProduct): void
	{
		$basePath = $this->container->getParameter('wwwDir') . '/userfiles/' . Product::FILE_DIR;
		$clonedRelations = [];
		$targetProduct->files->unrelateAll();

		foreach ($sourceProduct->files as $file) {
			$fileClone = clone $file;
			$fileClone->setValue('uuid', DIConnection::generateUuid());
			$explodedFilename = \explode('.', $fileClone->fileName);
			$newFilename = Uuid::uuid4() . '.' . \end($explodedFilename);

			FileSystem::copy($basePath . \DIRECTORY_SEPARATOR . $fileClone->fileName, $basePath . \DIRECTORY_SEPARATOR . $newFilename);

			$fileClone->fileName = $newFilename;
			$fileCloneArray = $fileClone->toArray(includePK: false);
			$fileCloneArray['product'] = $targetProduct->getPK();

			$clonedRelations[] = $targetProduct->files->getRepository()->createOne($fileCloneArray);
		}

		$targetProduct->files->relate(\array_map(function (File $file) {
			return $file->getPK();
		}, $clonedRelations), false);
	}

	private function clonePhotosRelation(Product $sourceProduct, Product $targetProduct, string $relationName): void
	{
		$clonedPhotos = [];
		$basePath = $this->container->getParameter('wwwDir') . '/userfiles/' . Product::GALLERY_DIR;

		/** @var \StORM\RelationCollection $sourceRelationValue */
		$sourceRelationValue = $sourceProduct->{$relationName};
		$relationRepository = $sourceRelationValue->getRepository();

		$targetProduct->{$relationName}->unrelateAll();

		/** @var \Eshop\DB\Photo $photo */
		foreach ($sourceRelationValue as $photo) {
			$photoClone = clone $photo;
			$explodedFilename = \explode('.', $photo->fileName);
			$newFilename = Uuid::uuid4() . '.' . \end($explodedFilename);

			try {
				$imageO = Image::fromFile($basePath . '/origin/' . $photoClone->fileName);
				$imageD = Image::fromFile($basePath . '/origin/' . $photoClone->fileName);
				$imageT = Image::fromFile($basePath . '/origin/' . $photoClone->fileName);

				$imageD->resize(600, null);
				$imageT->resize(300, null);

				$imageO->save($basePath . '/origin/' . $newFilename);
				$imageD->save($basePath . '/detail/' . $newFilename);
				$imageT->save($basePath . '/thumb/' . $newFilename);
			} catch (\Exception $e) {
			}

			$photoClone->fileName = $newFilename;
			$relationArray = $photoClone->toArray(includePK: false);
			$relationArray['product'] = $targetProduct->getPK();
			$clonedPhotos[] = $relationRepository->createOne($relationArray);
		}

		$targetProduct->{$relationName}->relate(\array_map(function (Entity $photo) {
			return $photo->getPK();
		}, $clonedPhotos));
	}
}
