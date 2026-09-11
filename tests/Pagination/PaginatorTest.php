<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\Pagination;

use App\Entity\Post;
use App\Pagination\Paginator;
use App\Repository\PostRepository;
use Doctrine\ORM\Tools\Pagination\OffsetPaginator;
use Doctrine\ORM\Tools\Pagination\Page;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Tests the pagination helper against the real query used by the blog.
 *
 * This also guards the migration away from the deprecated
 * "Doctrine\ORM\Tools\Pagination\Paginator": the helper must keep returning the
 * same pages no matter which Doctrine paginator backs it, and it must pick the
 * non-deprecated OffsetPaginator whenever the installed doctrine/orm ships it.
 */
final class PaginatorTest extends KernelTestCase
{
    private PostRepository $posts;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var PostRepository $posts */
        $posts = self::getContainer()->get(PostRepository::class);
        $this->posts = $posts;
    }

    public function testFirstPageIsLimitedToThePageSize(): void
    {
        $paginator = $this->posts->findLatest(1);
        $total = $paginator->getNumResults();

        self::assertGreaterThan(
            Paginator::PAGE_SIZE,
            $total,
            'The fixtures must provide more than one page of published posts.'
        );

        $results = iterator_to_array($paginator->getResults());

        self::assertSame(1, $paginator->getCurrentPage());
        self::assertCount(Paginator::PAGE_SIZE, $results);
        self::assertContainsOnlyInstancesOf(Post::class, $results);
        self::assertFalse($paginator->hasPreviousPage());
        self::assertTrue($paginator->hasNextPage());
        self::assertTrue($paginator->hasToPaginate());
    }

    public function testLastPageHoldsTheRemainingResults(): void
    {
        $total = $this->posts->findLatest(1)->getNumResults();
        $lastPage = (int) ceil($total / Paginator::PAGE_SIZE);

        $paginator = $this->posts->findLatest($lastPage);

        self::assertSame($lastPage, $paginator->getCurrentPage());
        self::assertSame($lastPage, $paginator->getLastPage());
        self::assertSame($total, $paginator->getNumResults());
        self::assertTrue($paginator->hasPreviousPage());
        self::assertFalse($paginator->hasNextPage());

        self::assertCount(
            $total - (($lastPage - 1) * Paginator::PAGE_SIZE),
            iterator_to_array($paginator->getResults())
        );
    }

    /**
     * Non-positive page numbers must be clamped to the first page instead of
     * producing a negative offset (which Window would reject outright).
     */
    public function testNonPositivePageFallsBackToTheFirstPage(): void
    {
        $paginator = $this->posts->findLatest(0);

        self::assertSame(1, $paginator->getCurrentPage());
        self::assertFalse($paginator->hasPreviousPage());
        self::assertCount(Paginator::PAGE_SIZE, iterator_to_array($paginator->getResults()));
    }

    /**
     * The RSS template iterates the results twice ("last" plus the full loop),
     * so whatever getResults() hands back must survive a second traversal.
     */
    public function testResultsCanBeTraversedMoreThanOnce(): void
    {
        $results = $this->posts->findLatest(1)->getResults();

        self::assertSame(
            array_map(static fn (Post $post): string => $post->getSlug(), iterator_to_array($results)),
            array_map(static fn (Post $post): string => $post->getSlug(), iterator_to_array($results))
        );
    }

    /**
     * Regression test for FIX-248: as soon as doctrine/orm ships the stateless
     * OffsetPaginator, the helper must use it instead of the deprecated
     * Doctrine\ORM\Tools\Pagination\Paginator. OffsetPaginator is the only one of
     * the two that returns an immutable Page, so the returned results prove which
     * branch ran.
     */
    public function testTheDeprecatedDoctrinePaginatorIsNotUsedWhenOffsetPaginatorExists(): void
    {
        if (!class_exists(OffsetPaginator::class)) {
            self::markTestSkipped('The installed doctrine/orm predates OffsetPaginator, so the legacy paginator is still used.');
        }

        $results = $this->posts->findLatest(1)->getResults();

        self::assertInstanceOf(Page::class, $results);
        self::assertCount(Paginator::PAGE_SIZE, $results);
    }
}