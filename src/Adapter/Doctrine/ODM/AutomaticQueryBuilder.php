<?php

/*
 * Symfony DataTables Bundle
 * (c) Omines Internetbureau B.V. - https://omines.nl/
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Omines\DataTablesBundle\Adapter\Doctrine\ODM;

use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Mapping\ClassMetadata;
use Doctrine\ODM\MongoDB\Query\Builder;
use Omines\DataTablesBundle\Column\AbstractColumn;
use Omines\DataTablesBundle\DataTableState;

/**
 * @author Giovanni Albero <giovannialbero.solinf@gmail.com>
 */
class AutomaticQueryBuilder implements QueryBuilderProcessorInterface
{
    /**
     * @var DocumentManager
     */
    private $dm;

    /**
     * @var ClassMetadata
     */
    private $metadata;

    /**
     * @var string
     */
    private $documentName;

    /**
     * @var string
     */
    private $documentShortName;

    /**
     * @var array
     */
    private $selectColumns = [];

    /**
     * @var array
     */
    private $joins = [];

    /**
     * AutomaticQueryBuilder constructor.
     *
     * @param DocumentManager $dm
     * @param ClassMetadata   $metadata
     */
    public function __construct(DocumentManager $dm, ClassMetadata $metadata)
    {
        $this->dm = $dm;
        $this->metadata = $metadata;

        $this->documentName = $this->metadata->getName();
        $this->documentShortName = mb_strtolower($this->metadata->getReflectionClass()->getShortName());
    }

    /**
     * @param Builder        $queryBuilder
     * @param DataTableState $state
     *
     * @throws \Doctrine\Common\Persistence\Mapping\MappingException
     * @throws \ReflectionException
     */
    public function process(Builder $queryBuilder, DataTableState $state)
    {
        if (empty($this->selectColumns)) {
            foreach ($state->getDataTable()->getColumns() as $column) {
                $this->processColumn($column);
            }
        }

        $queryBuilder->find($this->documentName);
    }

    /**
     * @param AbstractColumn $column
     *
     * @throws \Doctrine\Common\Persistence\Mapping\MappingException
     * @throws \ReflectionException
     */
    protected function processColumn(AbstractColumn $column)
    {
        $field = $column->getField();

        // Default to the column name if that corresponds to a field mapping
        if (!isset($field) && isset($this->metadata->fieldMappings[$column->getName()])) {
            $field = $column->getName();
        }
        if (null !== $field) {
            $this->addSelectColumns($column, $field);
        }
    }

    /**
     * @param AbstractColumn $column
     * @param string         $field
     *
     * @throws \Doctrine\Common\Persistence\Mapping\MappingException
     * @throws \ReflectionException
     */
    private function addSelectColumns(AbstractColumn $column, string $field)
    {
        $currentPart = $this->documentShortName;
        $currentAlias = $currentPart;
        $metadata = $this->metadata;
        $parts = explode('.', $field);

        if (count($parts) > 1 && $parts[0] === $currentPart) {
            array_shift($parts);
        }

        while (count($parts) > 1) {
            $previousPart = $currentPart;
            $previousAlias = $currentAlias;
            $currentPart = array_shift($parts);
            $currentAlias = ($previousPart === $this->documentShortName ? '' : $previousPart . '_') . $currentPart;

            $this->joins[$previousAlias . '.' . $currentPart] = ['alias' => $currentAlias, 'type' => 'join'];

            $metadata = $this->setIdentifierFromAssociation($currentAlias, $currentPart, $metadata);
        }

        $this->addSelectColumn($currentAlias, $this->getIdentifier($metadata));
        $this->addSelectColumn($currentAlias, $parts[0]);
    }

    private function addSelectColumn($columnTableName, $data)
    {
        if (isset($this->selectColumns[$columnTableName])) {
            if (!in_array($data, $this->selectColumns[$columnTableName], true)) {
                $this->selectColumns[$columnTableName][] = $data;
            }
        } else {
            $this->selectColumns[$columnTableName][] = $data;
        }

        return $this;
    }

    private function getIdentifier(ClassMetadata $metadata)
    {
        $identifiers = $metadata->getIdentifierFieldNames();

        return array_shift($identifiers);
    }

    /**
     * @param string        $association
     * @param string        $key
     * @param ClassMetadata $metadata
     *
     * @throws \Doctrine\Common\Persistence\Mapping\MappingException
     * @throws \ReflectionException
     *
     * @return \Doctrine\Common\Persistence\Mapping\ClassMetadata|ClassMetadata
     */
    private function setIdentifierFromAssociation(string $association, string $key, ClassMetadata $metadata)
    {
        $targetDocumentClass = $metadata->getAssociationTargetClass($key);

        /** @var ClassMetadata $targetMetadata */
        $targetMetadata = $this->dm->getMetadataFactory()->getMetadataFor($targetDocumentClass);
        $this->addSelectColumn($association, $this->getIdentifier($targetMetadata));

        return $targetMetadata;
    }
}
