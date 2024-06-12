<?php

namespace Utopia\Database;

use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Exception\Limit;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Mirroring\Filter;
use Utopia\Database\Validator\Authorization;

class Mirror extends Database
{
    protected Database $source;
    protected ?Database $destination;

    /**
     * Filters to apply to documents before writing to the destination database
     *
     * @var array<Filter>
     */
    protected array $writeFilters = [];

    /**
     * Callbacks to run when an error occurs on the destination database
     *
     * @var array<callable(string, \Throwable): void>
     */
    protected array $errorCallbacks = [];

    /**
     * Collections that should only be present in the source database
     */
    protected const SOURCE_ONLY_COLLECTIONS = [
        'upgrades',
    ];

    /**
     * @param Database $source
     * @param ?Database $destination
     * @param array<Filter> $filters
     */
    public function __construct(
        Database $source,
        ?Database $destination = null,
        array $filters = [],
    ) {
        parent::__construct(
            $source->getAdapter(),
            $source->getCache()
        );
        $this->source = $source;
        $this->destination = $destination;
        $this->writeFilters = $filters;
    }

    public function getSource(): Database
    {
        return $this->source;
    }

    public function getDestination(): ?Database
    {
        return $this->destination;
    }

    /**
     * @return array<Filter>
     */
    public function getWriteFilters(): array
    {
        return $this->writeFilters;
    }

    /**
     * @param callable(string, \Throwable): void $callback
     * @return void
     */
    public function onError(callable $callback): void
    {
        $this->errorCallbacks[] = $callback;
    }

    /**
     * @param string $method
     * @param array<mixed> $args
     * @return mixed
     */
    protected function delegate(string $method, array $args = []): mixed
    {
        $result = $this->source->{$method}(...$args);

        if ($this->destination === null) {
            return $result;
        }

        try {
            $result = $this->destination->{$method}(...$args);
        } catch (\Throwable $err) {
            $this->logError($method, $err);
        }

        return $result;
    }

    public function setDatabase(string $name): static
    {
        $this->delegate('setDatabase', [$name]);

        return $this;
    }

    public function setNamespace(string $namespace): static
    {
        $this->delegate('setNamespace', [$namespace]);

        return $this;
    }

    public function setSharedTables(bool $sharedTables): static
    {
        $this->delegate('setSharedTables', [$sharedTables]);

        return $this;
    }

    public function setTenant(?int $tenant): static
    {
        $this->delegate('setTenant', [$tenant]);

        return $this;
    }

    public function setPreserveDates(bool $preserve): static
    {
        $this->delegate('setPreserveDates', [$preserve]);

        $this->preserveDates = $preserve;

        return $this;
    }

    public function enableValidation(): static
    {
        $this->delegate('enableValidation');

        $this->validate = true;

        return $this;
    }

    public function disableValidation(): static
    {
        $this->delegate('disableValidation');

        $this->validate = false;

        return $this;
    }

    public function on(string $event, string $name, callable $callback): static
    {
        $this->source->on($event, $name, $callback);

        return $this;
    }

    protected function trigger(string $event, mixed $args = null): void
    {
        $this->source->trigger($event, $args);
    }

    public function silent(callable $callback, array $listeners = null): mixed
    {
        return $this->source->silent($callback, $listeners);
    }

    public function withRequestTimestamp(?\DateTime $requestTimestamp, callable $callback): mixed
    {
        return $this->delegate('withRequestTimestamp', [$requestTimestamp, $callback]);
    }

    public function exists(?string $database = null, ?string $collection = null): bool
    {
        return $this->delegate('exists', [$database, $collection]);
    }

    public function create(?string $database = null): bool
    {
        return $this->delegate('create', [$database]);
    }

    public function delete(?string $database = null): bool
    {
        return $this->delegate('delete', [$database]);
    }

    public function createCollection(string $id, array $attributes = [], array $indexes = [], array $permissions = null, bool $documentSecurity = true): Document
    {
        $result = $this->source->createCollection(
            $id,
            $attributes,
            $indexes,
            $permissions,
            $documentSecurity
        );

        if ($this->destination === null) {
            return $result;
        }

        try {
            foreach ($this->writeFilters as $filter) {
                $result = $filter->beforeCreateCollection(
                    source: $this->source,
                    destination: $this->destination,
                    collectionId: $id,
                    collection: $result,
                );
            }

            $this->destination->createCollection(
                $id,
                $attributes,
                $indexes,
                $permissions,
                $documentSecurity
            );

            $this->createUpgrades();

            $this->source->createDocument('upgrades', new Document([
                '$id' => $id,
                'collectionId' => $id,
                'status' => 'upgraded'
            ]));
        } catch (\Throwable $err) {
            $this->logError('createCollection', $err);
        }
        return $result;
    }

    public function updateCollection(string $id, array $permissions, bool $documentSecurity): Document
    {
        $result = $this->source->updateCollection($id, $permissions, $documentSecurity);

        if ($this->destination === null) {
            return $result;
        }

        try {
            foreach ($this->writeFilters as $filter) {
                $result = $filter->beforeUpdateCollection(
                    source: $this->source,
                    destination: $this->destination,
                    collectionId: $id,
                    collection: $result,
                );
            }

            $this->destination->updateCollection($id, $permissions, $documentSecurity);
        } catch (\Throwable $err) {
            $this->logError('updateCollection', $err);
        }

        return $result;
    }

    public function deleteCollection(string $id): bool
    {
        $result = $this->source->deleteCollection($id);

        if ($this->destination === null) {
            return $result;
        }

        try {
            $this->destination->deleteCollection($id);

            foreach ($this->writeFilters as $filter) {
                $filter->beforeDeleteCollection(
                    source: $this->source,
                    destination: $this->destination,
                    collectionId: $id,
                );
            }
        } catch (\Throwable $err) {
            $this->logError('deleteCollection', $err);
        }

        return $result;
    }

    public function createAttribute(string $collection, string $id, string $type, int $size, bool $required, $default = null, bool $signed = true, bool $array = false, string $format = null, array $formatOptions = [], array $filters = []): bool
    {
        $result = $this->source->createAttribute(
            $collection,
            $id,
            $type,
            $size,
            $required,
            $default,
            $signed,
            $array,
            $format,
            $formatOptions,
            $filters
        );

        if ($this->destination === null) {
            return $result;
        }

        try {
            $document = new Document([
                '$id' => $id,
                'type' => $type,
                'size' => $size,
                'required' => $required,
                'default' => $default,
                'signed' => $signed,
                'array' => $array,
                'format' => $format,
                'formatOptions' => $formatOptions,
                'filters' => $filters,
            ]);

            foreach ($this->writeFilters as $filter) {
                $document = $filter->beforeCreateAttribute(
                    source: $this->source,
                    destination: $this->destination,
                    collectionId: $collection,
                    attributeId: $id,
                    attribute: $document,
                );
            }

            $result = $this->destination->createAttribute(
                $collection,
                $document->getId(),
                $document->getAttribute('type'),
                $document->getAttribute('size'),
                $document->getAttribute('required'),
                $document->getAttribute('default'),
                $document->getAttribute('signed'),
                $document->getAttribute('array'),
                $document->getAttribute('format'),
                $document->getAttribute('formatOptions'),
                $document->getAttribute('filters'),
            );
        } catch (\Throwable $err) {
            $this->logError('createAttribute', $err);
        }

        return $result;
    }

    public function updateAttribute(string $collection, string $id, string $type = null, int $size = null, bool $required = null, mixed $default = null, bool $signed = null, bool $array = null, string $format = null, ?array $formatOptions = null, ?array $filters = null): Document
    {
        $document = $this->source->updateAttribute(
            $collection,
            $id,
            $type,
            $size,
            $required,
            $default,
            $signed,
            $array,
            $format,
            $formatOptions,
            $filters
        );

        if ($this->destination === null) {
            return $document;
        }

        try {
            foreach ($this->writeFilters as $filter) {
                $document = $filter->beforeUpdateAttribute(
                    source: $this->source,
                    destination: $this->destination,
                    collectionId: $collection,
                    attributeId: $id,
                    attribute: $document,
                );
            }

            $this->destination->updateAttribute(
                $collection,
                $id,
                $document->getAttribute('type'),
                $document->getAttribute('size'),
                $document->getAttribute('required'),
                $document->getAttribute('default'),
                $document->getAttribute('signed'),
                $document->getAttribute('array'),
                $document->getAttribute('format'),
                $document->getAttribute('formatOptions'),
                $document->getAttribute('filters')
            );
        } catch (\Throwable $err) {
            $this->logError('updateAttribute', $err);
        }

        return $document;
    }

    public function deleteAttribute(string $collection, string $id): bool
    {
        $result = $this->source->deleteAttribute($collection, $id);

        if ($this->destination === null) {
            return $result;
        }

        try {
            foreach ($this->writeFilters as $filter) {
                $filter->beforeDeleteAttribute(
                    source: $this->source,
                    destination: $this->destination,
                    collectionId: $collection,
                    attributeId: $id,
                );
            }

            $this->destination->deleteAttribute($collection, $id);
        } catch (\Throwable $err) {
            $this->logError('deleteAttribute', $err);
        }

        return $result;
    }

    public function createIndex(string $collection, string $id, string $type, array $attributes, array $lengths = [], array $orders = []): bool
    {
        $result = $this->source->createIndex($collection, $id, $type, $attributes, $lengths, $orders);

        if ($this->destination === null) {
            return $result;
        }

        try {
            $document = new Document([
                '$id' => $id,
                'type' => $type,
                'attributes' => $attributes,
                'lengths' => $lengths,
                'orders' => $orders,
            ]);

            foreach ($this->writeFilters as $filter) {
                $document = $filter->beforeCreateIndex(
                    source: $this->source,
                    destination: $this->destination,
                    collectionId: $collection,
                    indexId: $id,
                    index: $document,
                );
            }

            $result = $this->destination->createIndex(
                $collection,
                $document->getId(),
                $document->getAttribute('type'),
                $document->getAttribute('attributes'),
                $document->getAttribute('lengths'),
                $document->getAttribute('orders')
            );
        } catch (\Throwable $err) {
            $this->logError('createIndex', $err);
        }

        return $result;
    }

    public function deleteIndex(string $collection, string $id): bool
    {
        $result = $this->source->deleteIndex($collection, $id);

        if ($this->destination === null) {
            return $result;
        }

        try {
            $this->destination->deleteIndex($collection, $id);

            foreach ($this->writeFilters as $filter) {
                $filter->beforeDeleteIndex(
                    source: $this->source,
                    destination: $this->destination,
                    collectionId: $collection,
                    indexId: $id,
                );
            }
        } catch (\Throwable $err) {
            $this->logError('deleteIndex', $err);
        }

        return $result;
    }

    public function createDocument(string $collection, Document $document): Document
    {
        $document = $this->source->createDocument($collection, $document);

        if (
            \in_array($collection, self::SOURCE_ONLY_COLLECTIONS)
            || $this->destination === null
        ) {
            return $document;
        }

        $upgrade = $this->getUpgradeStatus($collection);
        if ($upgrade === null || $upgrade->getAttribute('status', '') !== 'upgraded') {
            return $document;
        }

        try {
            $clone = clone $document;

            foreach ($this->writeFilters as $filter) {
                $clone = $filter->beforeCreateDocument(
                    source: $this->source,
                    destination: $this->destination,
                    collectionId: $collection,
                    document: $clone,
                );
            }

            $this->destination->setPreserveDates(true);
            $document = $this->destination->createDocument($collection, $clone);
            $this->destination->setPreserveDates(false);

            foreach ($this->writeFilters as $filter) {
                $filter->afterCreateDocument(
                    source: $this->source,
                    destination: $this->destination,
                    collectionId: $collection,
                    document: $clone,
                );
            }
        } catch (\Throwable $err) {
            $this->logError('createDocument', $err);
        }

        return $document;
    }

    public function createDocuments(
        string $collection,
        array $documents,
        int $batchSize = self::INSERT_BATCH_SIZE
    ): array {
        $documents = $this->source->createDocuments($collection, $documents, $batchSize);

        if (
            \in_array($collection, self::SOURCE_ONLY_COLLECTIONS)
            || $this->destination === null
        ) {
            return $documents;
        }

        $upgrade = $this->getUpgradeStatus($collection);
        if ($upgrade === null || $upgrade->getAttribute('status', '') !== 'upgraded') {
            return $documents;
        }

        try {
            $clones = [];

            foreach ($documents as $document) {
                $clone = clone $document;

                foreach ($this->writeFilters as $filter) {
                    $clone = $filter->beforeCreateDocument(
                        source: $this->source,
                        destination: $this->destination,
                        collectionId: $collection,
                        document: $clone,
                    );
                }

                $clones[] = $clone;
            }

            $this->destination->setPreserveDates(true);
            $this->destination->createDocuments($collection, $clones, $batchSize);
            $this->destination->setPreserveDates(false);

            foreach ($clones as $clone) {
                foreach ($this->writeFilters as $filter) {
                    $filter->afterCreateDocument(
                        source: $this->source,
                        destination: $this->destination,
                        collectionId: $collection,
                        document: $clone,
                    );
                }
            }

        } catch (\Throwable $err) {
            $this->logError('createDocuments', $err);
        }

        return $documents;
    }

    public function updateDocument(string $collection, string $id, Document $document): Document
    {
        $document = $this->source->updateDocument($collection, $id, $document);

        if (
            \in_array($collection, self::SOURCE_ONLY_COLLECTIONS)
            || $this->destination === null
        ) {
            return $document;
        }

        $upgrade = $this->getUpgradeStatus($collection);
        if ($upgrade === null || $upgrade->getAttribute('status', '') !== 'upgraded') {
            return $document;
        }

        try {
            $clone = clone $document;

            foreach ($this->writeFilters as $filter) {
                $clone = $filter->beforeUpdateDocument(
                    source: $this->source,
                    destination: $this->destination,
                    collectionId: $collection,
                    document: $clone,
                );
            }

            $this->destination->setPreserveDates(true);
            $this->destination->updateDocument($collection, $id, $clone);
            $this->destination->setPreserveDates(false);

            foreach ($this->writeFilters as $filter) {
                $filter->afterUpdateDocument(
                    source: $this->source,
                    destination: $this->destination,
                    collectionId: $collection,
                    document: $clone,
                );
            }
        } catch (\Throwable $err) {
            $this->logError('updateDocument', $err);
        }

        return $document;
    }

    public function updateDocuments(
        string $collection,
        array $documents,
        int $batchSize = self::INSERT_BATCH_SIZE
    ): array {
        $documents = $this->source->updateDocuments($collection, $documents, $batchSize);

        if (
            \in_array($collection, self::SOURCE_ONLY_COLLECTIONS)
            || $this->destination === null
        ) {
            return $documents;
        }

        $upgrade = $this->getUpgradeStatus($collection);
        if ($upgrade === null || $upgrade->getAttribute('status', '') !== 'upgraded') {
            return $documents;
        }

        try {
            $clones = [];

            foreach ($documents as $document) {
                $clone = clone $document;

                foreach ($this->writeFilters as $filter) {
                    $clone = $filter->beforeUpdateDocument(
                        source: $this->source,
                        destination: $this->destination,
                        collectionId: $collection,
                        document: $clone,
                    );
                }

                $clones[] = $clone;
            }

            $this->destination->setPreserveDates(true);
            $this->destination->updateDocuments($collection, $clones, $batchSize);
            $this->destination->setPreserveDates(false);

            foreach ($clones as $clone) {
                foreach ($this->writeFilters as $filter) {
                    $filter->afterUpdateDocument(
                        source: $this->source,
                        destination: $this->destination,
                        collectionId: $collection,
                        document: $clone,
                    );
                }
            }
        } catch (\Throwable $err) {
            $this->logError('updateDocuments', $err);
        }

        return $documents;
    }

    public function deleteDocument(string $collection, string $id): bool
    {
        $result = $this->source->deleteDocument($collection, $id);

        if (
            \in_array($collection, self::SOURCE_ONLY_COLLECTIONS)
            || $this->destination === null
        ) {
            return $result;
        }

        $upgrade = $this->getUpgradeStatus($collection);
        if ($upgrade === null || $upgrade->getAttribute('status', '') !== 'upgraded') {
            return $result;
        }

        try {


            $this->destination->deleteDocument($collection, $id);

            foreach ($this->writeFilters as $filter) {
                $filter->afterDeleteDocument(
                    source: $this->source,
                    destination: $this->destination,
                    collectionId: $collection,
                    documentId: $id,
                );
            }
        } catch (\Throwable $err) {
            $this->logError('deleteDocument', $err);
        }

        return $result;
    }

    public function updateAttributeRequired(string $collection, string $id, bool $required): Document
    {
        return $this->delegate('updateAttributeRequired', [$collection, $id, $required]);
    }

    public function updateAttributeFormat(string $collection, string $id, string $format): Document
    {
        return $this->delegate('updateAttributeFormat', [$collection, $id, $format]);
    }

    public function updateAttributeFormatOptions(string $collection, string $id, array $formatOptions): Document
    {
        return $this->delegate('updateAttributeFormatOptions', [$collection, $id, $formatOptions]);
    }

    public function updateAttributeFilters(string $collection, string $id, array $filters): Document
    {
        return $this->delegate('updateAttributeFilters', [$collection, $id, $filters]);
    }

    public function updateAttributeDefault(string $collection, string $id, mixed $default = null): Document
    {
        return $this->delegate('updateAttributeDefault', [$collection, $id, $default]);
    }

    public function renameAttribute(string $collection, string $old, string $new): bool
    {
        return $this->delegate('renameAttribute', [$collection, $old, $new]);
    }

    public function createRelationship(
        string $collection,
        string $relatedCollection,
        string $type,
        bool $twoWay = false,
        ?string $id = null,
        ?string $twoWayKey = null,
        string $onDelete = Database::RELATION_MUTATE_RESTRICT
    ): bool {
        return $this->delegate('createRelationship', [$collection, $relatedCollection, $type, $twoWay, $id, $twoWayKey, $onDelete]);
    }

    public function updateRelationship(
        string $collection,
        string $id,
        ?string $newKey = null,
        ?string $newTwoWayKey = null,
        ?bool $twoWay = null,
        ?string $onDelete = null
    ): bool {
        return $this->delegate('updateRelationship', [$collection, $id, $newKey, $newTwoWayKey, $twoWay, $onDelete]);
    }

    public function deleteRelationship(string $collection, string $id): bool
    {
        return $this->delegate('deleteRelationship', [$collection, $id]);
    }


    public function renameIndex(string $collection, string $old, string $new): bool
    {
        return $this->delegate('renameIndex', [$collection, $old, $new]);
    }

    public function increaseDocumentAttribute(string $collection, string $id, string $attribute, int|float $value = 1, int|float|null $max = null): bool
    {
        return $this->delegate('increaseDocumentAttribute', [$collection, $id, $attribute, $value, $max]);
    }

    public function decreaseDocumentAttribute(string $collection, string $id, string $attribute, int|float $value = 1, int|float|null $min = null): bool
    {
        return $this->delegate('decreaseDocumentAttribute', [$collection, $id, $attribute, $value, $min]);
    }

    /**
     * @throws Limit
     * @throws DuplicateException
     * @throws Exception
     */
    public function createUpgrades(): void
    {
        try {
            $this->source->createCollection(
                id: 'upgrades',
                attributes: [
                    new Document([
                        '$id' => ID::custom('collectionId'),
                        'type' => Database::VAR_STRING,
                        'size' => Database::LENGTH_KEY,
                        'required' => true,
                        'signed' => true,
                        'array' => false,
                        'filters' => [],
                        'default' => null,
                        'format' => ''
                    ]),
                    new Document([
                        '$id' => ID::custom('status'),
                        'type' => Database::VAR_STRING,
                        'size' => Database::LENGTH_KEY,
                        'required' => false,
                        'signed' => true,
                        'array' => false,
                        'filters' => [],
                        'default' => null,
                        'format' => ''
                    ]),
                ],
                indexes: [
                    new Document([
                        '$id' => ID::custom('_unique_collection'),
                        'type' => Database::INDEX_UNIQUE,
                        'attributes' => ['collectionId'],
                        'lengths' => [Database::LENGTH_KEY],
                        'orders' => [],
                    ]),
                    new Document([
                        '$id' => ID::custom('_status_index'),
                        'type' => Database::INDEX_KEY,
                        'attributes' => ['status'],
                        'lengths' => [Database::LENGTH_KEY],
                        'orders' => [Database::ORDER_ASC],
                    ]),
                ],
            );
        } catch (DuplicateException) {
            // Ignore
        }
    }

    /**
     * @throws Exception
     */
    protected function getUpgradeStatus(string $collection): ?Document
    {
        if ($collection === 'upgrades' || $collection === Database::METADATA) {
            return new Document();
        }

        return Authorization::skip(function () use ($collection) {
            try {
                return $this->getDocument('upgrades', $collection);
            } catch (\Throwable) {
                return;
            }
        });
    }

    protected function logError(string $action, \Throwable $err): void
    {
        foreach ($this->errorCallbacks as $callback) {
            $callback($action, $err);
        }
    }
}
