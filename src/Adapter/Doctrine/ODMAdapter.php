<?php

namespace Omines\DataTablesBundle\Adapter\Doctrine;

use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Mapping\ClassMetadata;
use Omines\DataTablesBundle\Adapter\AbstractAdapter;
use Omines\DataTablesBundle\Adapter\Doctrine\ODM\AutomaticQueryBuilder;
use Omines\DataTablesBundle\Adapter\Doctrine\ODM\QueryBuilderProcessorInterface;
use Omines\DataTablesBundle\Adapter\Doctrine\ODM\SearchCriteriaProvider;
use Omines\DataTablesBundle\Adapter\AdapterQuery;
use Omines\DataTablesBundle\Column\AbstractColumn;
use Omines\DataTablesBundle\DataTableState;
use Omines\DataTablesBundle\Exception\InvalidConfigurationException;
use Doctrine\ODM\MongoDB\Query\Builder;
use Omines\DataTablesBundle\Exception\MissingDependencyException;
use Symfony\Bridge\Doctrine\RegistryInterface;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @author Giovanni Albero <giovannialbero.solinf@gmail.com>
 */
class ODMAdapter extends AbstractAdapter
{
    /**
     * @var RegistryInterface
     */
    private $registry;

    /**
     * @var DocumentManager
     */
    private $manager;

    /**
     * @var ClassMetadata
     */
    private $metadata;

    /**
     * @var QueryBuilderProcessorInterface[]
     */
    private $queryBuilderProcessors;

    /**
     * @var QueryBuilderProcessorInterface[]
     */
    private $criteriaProcessors;

    /**
     * ODMAdapter constructor.
     *
     * @param RegistryInterface $registry
     */
    public function __construct(RegistryInterface $registry = null)
    {
        if (null === $registry) {
            throw new MissingDependencyException('Install doctrine/mongodb-odm-bundle to use the ODMAdapter');
        }

        parent::__construct();
        $this->registry = $registry;
    }

    public function configure(array $options)
    {
        $resolver = new OptionsResolver();
        $this->configureOptions($resolver);

        $options = $resolver->resolve($options);

        // Enable automated mode or just get the general default entity manager
        if (null === ($this->manager = $this->registry->getManagerForClass($options['document']))) {
            throw new InvalidConfigurationException(sprintf('Doctrine has no manager for document "%s", is it correctly imported and referenced?', $options['document']));
        }

        $this->metadata = $this->manager->getClassMetadata($options['document']);
        if (empty($options['query'])) {
            $options['query'] = [new AutomaticQueryBuilder($this->manager, $this->metadata)];
        }

        $this->queryBuilderProcessors = $options['query'];
        $this->criteriaProcessors = $options['criteria'];
    }

    protected function prepareQuery(AdapterQuery $query)
    {
        $state = $query->getState();
        $query->set('qb', $builder = $this->createQueryBuilder($state));

        $this->buildCriteria($builder, $state);

        $query->setFilteredRows($builder->getQuery()->count());

        $collBuilder = new Builder($this->manager, $this->metadata->getName());
        $query->setTotalRows($collBuilder->getQuery()->count());
        $query->setIdentifierPropertyPath($this->metadata->identifier);
    }

    protected function mapPropertyPath(AdapterQuery $query, AbstractColumn $column)
    {
        return $column->getField();
    }

    /**
     * {@inheritdoc}
     */
    protected function getResults(AdapterQuery $query): \Traversable
    {
        /** @var Builder $builder */
        $builder = $query->get('qb');
        $state = $query->getState();

        foreach ($state->getOrderBy() as list($column, $direction)) {
            /** @var AbstractColumn $column */
            if ($column->isOrderable()) {
                $builder->sort($column->getOrderField(), $direction);
            }
        }

        if ($state->getLength() > 0) {
            $builder
                ->limit($state->getLength())
                ->skip($state->getStart())
            ;
        }

        foreach ($builder->getQuery()->getIterator() as $result) {
            yield $result;
        }
    }

    /**
     * @param DataTableState $state
     *
     * @return Builder
     */
    protected function createQueryBuilder(DataTableState $state): Builder
    {
        $queryBuilder = $this->manager->createQueryBuilder($this->metadata->getName());

        // Run all query builder processors in order
        foreach ($this->queryBuilderProcessors as $processor) {
            $processor->process($queryBuilder, $state);
        }

        return $queryBuilder;
    }

    /**
     * @param $builder
     * @param $state
     */
    protected function buildCriteria($builder, $state): void
    {
        foreach ($this->criteriaProcessors as $provider) {
            $provider->process($builder, $state);
        }
    }

    private function configureOptions(OptionsResolver $resolver)
    {
        $providerNormalizer = function (Options $options, $value) {
            return array_map([$this, 'normalizeProcessor'], (array) $value);
        };

        $resolver
            ->setDefaults([
                'query' => [],
                'criteria' => function (Options $options) {
                    return [new SearchCriteriaProvider()];
                },
            ])
            ->setRequired('document')
            ->setAllowedTypes('document', ['string'])
            ->setAllowedTypes('query', [QueryBuilderProcessorInterface::class, 'array', 'callable'])
            ->setAllowedTypes('criteria', [QueryBuilderProcessorInterface::class, 'array', 'callable', 'null'])
            ->setNormalizer('query', $providerNormalizer)
            ->setNormalizer('criteria', $providerNormalizer)
        ;
    }

    /**
     * @param callable|QueryBuilderProcessorInterface $provider
     *
     * @return QueryBuilderProcessorInterface
     */
    private function normalizeProcessor($provider)
    {
        if ($provider instanceof QueryBuilderProcessorInterface) {
            return $provider;
        } elseif (is_callable($provider)) {
            return new class($provider) implements QueryBuilderProcessorInterface {
                private $callable;

                public function __construct(callable $value)
                {
                    $this->callable = $value;
                }

                public function process(Builder $queryBuilder, DataTableState $state)
                {
                    return call_user_func($this->callable, $queryBuilder, $state);
                }
            };
        }

        throw new InvalidConfigurationException('Provider must be a callable or implement QueryBuilderProcessorInterface');
    }
}