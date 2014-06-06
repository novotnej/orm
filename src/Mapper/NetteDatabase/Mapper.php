<?php

/**
 * This file is part of the Nextras\ORM library.
 *
 * @license    MIT
 * @link       https://github.com/nextras/orm
 * @author     Jan Skrasek
 */

namespace Nextras\Orm\Mapper\NetteDatabase;

use Nette\Database\Context;
use Nette\Database\IConventions;
use Nette\Database\IStructure;
use Nette\Database\Table\SqlBuilder;
use Nextras\Orm\Entity\Collection\Collection;
use Nextras\Orm\Entity\Collection\ICollection;
use Nextras\Orm\Entity\IEntity;
use Nextras\Orm\Entity\Reflection\PropertyMetadata;
use Nextras\Orm\Mapper\BaseMapper;
use Nextras\Orm\Mapper\IMapper;
use Nextras\Orm\Relationships\IRelationshipCollection;
use Nextras\Orm\StorageReflection\IDbStorageReflection;
use Nextras\Orm\StorageReflection\UnderscoredDbStorageReflection;
use stdClass;


/**
 * Mapper for Nette\Database.
 */
class Mapper extends BaseMapper
{
	/** @var Context */
	protected $databaseContext;

	/** @var IStructure */
	protected $databaseStructure;

	/** @var IConventions */
	protected $databaseConventions;

	/** @var array */
	protected $cacheRM = [];

	/** @var array */
	private static $transactions = [];


	public function __construct(Context $databaseContext)
	{
		$this->databaseContext = $databaseContext;
		$this->databaseStructure = $databaseContext->getStructure();
		$this->databaseConventions = $databaseContext->getConventions();
		$this->collectionCache = (object) NULL;
	}


	public function findAll()
	{
		return $this->createCollection();
	}


	public function builder()
	{
		return new SqlBuilder($this->getTableName(), $this->databaseContext->getConnection(), $this->databaseConventions);
	}


	public function createCollection()
	{
		return new Collection($this->createCollectionMapper());
	}


	public function toCollection(SqlBuilder $builder)
	{
		return new Collection(new SqlBuilderCollectionMapper($this->getRepository(), $this->databaseContext, $builder));
	}


	public function getManyHasManyParameters(IMapper $mapper)
	{
		return [
			$this->storageReflection->getManyHasManyStorageName($mapper),
			$this->storageReflection->getManyHasManyStoragePrimaryKeys($mapper),
		];
	}


	protected function createCollectionMapper()
	{
		return new CollectionMapper($this->getRepository(), $this->databaseContext, $this->getTableName());
	}


	// == Relationship mappers =========================================================================================


	public function createCollectionHasOne(IMapper $targetMapper, PropertyMetadata $metadata, IEntity $parent)
	{
		$relationshipMapper = $this->getRelationshipMapperHasOne($targetMapper, $metadata);
		return new Collection($this->createCollectionMapper(), $relationshipMapper, $parent);
	}


	public function createCollectionManyHasMany(IMapper $mapper, PropertyMetadata $metadata, IEntity $parent)
	{
		$relationshipMapper = $this->getRelationshipMapperManyHasMany($mapper, $metadata);
		$targetMapper = $metadata->args[2] ? $mapper : $this;
		return new Collection($targetMapper->createCollectionMapper(), $relationshipMapper, $parent);
	}


	public function createCollectionOneHasMany(IMapper $targetMapper, PropertyMetadata $metadata, IEntity $parent)
	{
		$relationshipMapper = $this->getRelationshipMapperOneHasMany($targetMapper, $metadata);
		return new Collection($this->createCollectionMapper(), $relationshipMapper, $parent);
	}


	public function getRelationshipMapperHasOne(IMapper $targetMapper, PropertyMetadata $metadata)
	{
		if (!isset($this->cacheRM[0][$metadata->name])) {
			$this->cacheRM[0][$metadata->name] = $this->createRelationshipMapperHasOne($targetMapper, $metadata);
		}

		return $this->cacheRM[0][$metadata->name];
	}


	public function getRelationshipMapperManyHasMany(IMapper $mapper, PropertyMetadata $metadata)
	{
		if (!isset($this->cacheRM[1][$metadata->name])) {
			$this->cacheRM[1][$metadata->name] = $this->createRelationshipMapperManyHasMany($mapper, $metadata);
		}

		return $this->cacheRM[1][$metadata->name];
	}


	public function getRelationshipMapperOneHasMany(IMapper $targetMapper, PropertyMetadata $metadata)
	{
		if (!isset($this->cacheRM[2][$metadata->name])) {
			$this->cacheRM[2][$metadata->name] = $this->createRelationshipMapperOneHasMany($targetMapper, $metadata);
		}

		return $this->cacheRM[2][$metadata->name];
	}


	protected function createRelationshipMapperHasOne(IMapper $targetMapper, PropertyMetadata $metadata)
	{
		return new RelationshipMapperHasOne($this->databaseContext, $targetMapper, $metadata);
	}


	protected function createRelationshipMapperManyHasMany(IMapper $mapperTwo, PropertyMetadata $metadata)
	{
		return new RelationshipMapperManyHasMany($this->databaseContext, $this, $mapperTwo, $metadata);
	}


	protected function createRelationshipMapperOneHasMany(IMapper $targetMapper, PropertyMetadata $metadata)
	{
		return new RelationshipMapperOneHasMany($this->databaseContext, $targetMapper, $metadata);
	}


	protected function createStorageReflection()
	{
		return new UnderscoredDbStorageReflection($this, $this->databaseStructure);
	}


	// == Persistence API ==============================================================================================


	public function persist(IEntity $entity)
	{
		$this->begin();
		$id = $entity->getValue('id', TRUE);
		$data = $entity->toArray();

		$storageProperties = $entity->getMetadata()->storageProperties;
		foreach ($data as $key => $value) {
			if (!in_array($key, $storageProperties, TRUE) || $value instanceof IRelationshipCollection) {
				unset($data[$key]);
			}
			if ($value instanceof IEntity)  {
				$data[$key] = $value->id;
			}
		}

		unset($data['id']);

		$data = $this->getStorageReflection()->convertEntityToStorage($data);

		if (!$id) {
			$this->databaseContext->query('INSERT INTO ' . $this->getTableName() . ' ', $data);
			return $this->databaseContext->getInsertId();
		} else {
			$primary = [];
			$id = (array) $id;
			foreach ($this->getStorageReflection()->getStoragePrimaryKey() as $key) {
				$primary[$key] = array_shift($id);

			}
			$this->databaseContext->query('UPDATE ' . $this->getTableName() . ' SET', $data, 'WHERE ?', $primary);
			return $entity->id;
		}
	}


	public function remove(IEntity $entity)
	{
		$this->begin();

		$id = (array) $entity->id;
		$primary = [];
		foreach ($this->getStorageReflection()->getStoragePrimaryKey() as $key) {
			$primary[$key] = array_shift($id);
		}

		$this->databaseContext->query('DELETE FROM ' . $this->getTableName() . ' WHERE ?', $primary);
	}


	// == Transactions API =============================================================================================


	public function flush()
	{
		$hash = spl_object_hash($this->databaseContext);
		if (isset(self::$transactions[$hash])) {
			$this->databaseContext->commit();
			unset(self::$transactions[$hash]);
		}
	}


	public function rollback()
	{
		$hash = spl_object_hash($this->databaseContext);
		if (isset(self::$transactions[$hash])) {
			$this->databaseContext->rollback();
			unset(self::$transactions[$hash]);
		}
	}


	protected function begin()
	{
		$hash = spl_object_hash($this->databaseContext);
		if (!isset(self::$transactions[$hash])) {
			$this->databaseContext->beginTransaction();
			self::$transactions[$hash] = TRUE;
		}
	}

}