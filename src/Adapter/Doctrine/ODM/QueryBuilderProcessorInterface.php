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
use Omines\DataTablesBundle\DataTableState;

/**
 * @author Giovanni Albero <giovannialbero.solinf@gmail.com>
 */
interface QueryBuilderProcessorInterface
{
    public function process(Builder $queryBuilder, DataTableState $state);
}
