<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Pagination;

use Doctrine\ORM\QueryBuilder as DoctrineQueryBuilder;
use Doctrine\ORM\Tools\Pagination\CountWalker;
use Doctrine\ORM\Tools\Pagination\OffsetPaginator;
use Doctrine\ORM\Tools\Pagination\Page;
use Doctrine\ORM\Tools\Pagination\Paginator as DoctrinePaginator;
use Doctrine\ORM\Tools\Pagination\Window;

/**
 * @author Javier Eguiluz <javier.eguiluz@gmail.com>
 */
final class Paginator
{
    /**
     * Use constants to define configuration options that rarely change instead
     * of specifying them under parameters section in config/services.yaml file.
     *
     * See https://symfony.com/doc/current/best_practices.html#use-constants-to-define-options-that-rarely-change
     */
    final public const PAGE_SIZE = 10;

    private int $currentPage;
    private int $numResults;

    /**
     * @var \Traversable<array-key, object>
     */
    private \Traversable $results;

    public function __construct(
        private readonly DoctrineQueryBuilder $queryBuilder,
        private readonly int $pageSize = self::PAGE_SIZE,
    ) {
    }

    public function paginate(int $page = 1): self
    {
        $this->currentPage = max(1, $page);
        $firstResult = ($this->currentPage - 1) * $this->pageSize;

        // the first/max results are still applied to the query builder because the legacy
        // Doctrine paginator reads the pagination window from the query itself; OffsetPaginator
        // ignores them and overwrites both values on the queries it derives.
        $query = $this->queryBuilder
            ->setFirstResult($firstResult)
            ->setMaxResults($this->pageSize)
            ->getQuery();

        /** @var array<string, mixed> $joinDqlParts */
        $joinDqlParts = $this->queryBuilder->getDQLPart('join');

        if (0 === \count($joinDqlParts)) {
            $query->setHint(CountWalker::HINT_DISTINCT, false);
        }

        /** @var array<string, mixed> $havingDqlParts */
        $havingDqlParts = $this->queryBuilder->getDQLPart('having');

        $useOutputWalkers = \count($havingDqlParts ?: []) > 0;

        // doctrine/orm 3.7 deprecates Doctrine\ORM\Tools\Pagination\Paginator in favor of the
        // stateless OffsetPaginator, which returns an immutable page instead of mutating itself.
        if (class_exists(OffsetPaginator::class)) {
            $offsetPaginator = new OffsetPaginator(
                fetchJoinCollection: true,
                useOutputWalkers: $useOutputWalkers,
            );

            // Window::fromPageNumberAndSize() takes the 1-based page number, so it derives the
            // same offset as $firstResult above without duplicating the arithmetic.
            $window = Window::fromPageNumberAndSize($this->currentPage, $this->pageSize);

            // paginate() returns a WindowPage, which implements Page; that interface extends
            // Countable and IteratorAggregate, so the page itself already satisfies the
            // \Traversable<array-key, object> type of self::$results. Unlike the iterator of
            // the legacy paginator it is immutable and can be traversed more than once, which
            // the RSS template relies on (it reads both "last" and the full loop).
            /** @var Page<object> $resultPage */
            $resultPage = $offsetPaginator->paginate($query, $window);

            $this->results = $resultPage;
            $this->numResults = $resultPage->getTotalCount();

            return $this;
        }

        // Fallback for doctrine/orm < 3.7, which this project is still locked on (3.6.7) and
        // where OffsetPaginator does not exist yet. Remove this branch (and the DoctrinePaginator
        // import above) as soon as composer.lock requires doctrine/orm 3.7 or higher.
        /** @var DoctrinePaginator<object> $paginator */
        $paginator = new DoctrinePaginator($query, true);
        $paginator->setUseOutputWalkers($useOutputWalkers);

        $this->results = $paginator->getIterator();
        $this->numResults = $paginator->count();

        return $this;
    }

    public function getCurrentPage(): int
    {
        return $this->currentPage;
    }

    public function getLastPage(): int
    {
        return (int) ceil($this->numResults / $this->pageSize);
    }

    public function getPageSize(): int
    {
        return $this->pageSize;
    }

    public function hasPreviousPage(): bool
    {
        return $this->currentPage > 1;
    }

    public function getPreviousPage(): int
    {
        return max(1, $this->currentPage - 1);
    }

    public function hasNextPage(): bool
    {
        return $this->currentPage < $this->getLastPage();
    }

    public function getNextPage(): int
    {
        return min($this->getLastPage(), $this->currentPage + 1);
    }

    public function hasToPaginate(): bool
    {
        return $this->numResults > $this->pageSize;
    }

    public function getNumResults(): int
    {
        return $this->numResults;
    }

    /**
     * @return \Traversable<int, object>
     */
    public function getResults(): \Traversable
    {
        return $this->results;
    }
}