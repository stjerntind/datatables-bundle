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

use Doctrine\ODM\MongoDB\Query\Builder;
use Omines\DataTablesBundle\Column\AbstractColumn;
use Omines\DataTablesBundle\DataTableState;

/**
 * @author Giovanni Albero <giovannialbero.solinf@gmail.com>
 */
class SearchCriteriaProvider implements QueryBuilderProcessorInterface
{
    /**
     * {@inheritdoc}
     */
    public function process(Builder $queryBuilder, DataTableState $state)
    {
        $this->processSearchColumns($queryBuilder, $state);
        $this->processGlobalSearch($queryBuilder, $state);
    }

    /**
     * @param Builder        $queryBuilder
     * @param DataTableState $state
     */
    private function processSearchColumns(Builder $queryBuilder, DataTableState $state)
    {
        foreach ($state->getSearchColumns() as $searchInfo) {
            /** @var AbstractColumn $column */
            $column = $searchInfo['column'];
            $search = $searchInfo['search'];

            if (!empty($search) && null !== ($filter = $column->getFilter())) {
                $queryBuilder->addAnd($queryBuilder->expr()->field($column->getField())->operator($filter->getOperator(), $search));
            }
        }
    }

    /**
     * @param Builder        $queryBuilder
     * @param DataTableState $state
     */
    private function processGlobalSearch(Builder $queryBuilder, DataTableState $state)
    {
        if (!empty($globalSearch = $state->getGlobalSearch())) {
            foreach ($state->getDataTable()->getColumns() as $column) {
                $expr = $queryBuilder->expr();
                if ($column->isGlobalSearchable() && !empty($field = $column->getField())) {
                    $mongoRegex = new \MongoRegex('/^' . $globalSearch . '/i');
                    $queryBuilder->addOr($expr->field($field)->equals($mongoRegex));
                }
            }
        }
    }
}
