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
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Tests for the pagination helper built on top of the Doctrine paginator.
 *
 * The DAMADoctrineTestBundle extension wraps every test case in a transaction,
 * so these tests can query the fixture database safely.
 */
final class PaginatorTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager = $entityManager;
    }

    /**
     * Regression test for FIX-255.
     *
     * Doctrine ORM 3.7 deprecated Doctrine\ORM\Tools\Pagination\Paginator in favor
     * of OffsetPaginator, and it will be removed in ORM 4.0. The deprecation is
     * only reported through a @deprecated annotation (there is no runtime notice),
     * so this test asserts statically that no application class imports or
     * references the deprecated class anymore.
     */
    public function testApplicationCodeDoesNotUseTheDeprecatedDoctrinePaginator(): void
    {
        $deprecatedClass = 'Doctrine\ORM\Tools\Pagination\Paginator';
        $offenders = [];

        $files = new \RegexIterator(
            new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 2).'/src')),
            '/\.php$/'
        );

        /** @var \SplFileInfo $file */
        foreach ($files as $file) {
            $contents = file_get_contents($file->getPathname());
            self::assertIsString($contents);

            // matches both the `use ...\Paginator;` import and any inline FQCN usage,
            // while ignoring sibling classes such as `...\Pagination\PaginatorInterface`
            if (1 === preg_match('/\\\\?'.preg_quote($deprecatedClass, '/').'(?![\\\\a-zA-Z0-9_])/', $contents)) {
                $offenders[] = $file->getPathname();
            }
        }

        self::assertSame([], $offenders, \sprintf('No class in src/ may use the deprecated "%s".', $deprecatedClass));
    }

    public function testDeprecatedDoctrinePaginatorIsStillTheOneWeMigratedAwayFrom(): void
    {
        // guards the regression test above: if Doctrine ever un-deprecates or renames
        // the class, this test tells us why the assertion above became meaningless
        $reflection = new \ReflectionClass(\Doctrine\ORM\Tools\Pagination\Paginator::class);
        $docComment = $reflection->getDocComment();

        self::assertIsString($docComment);
        self::assertStringContainsString('@deprecated', $docComment);
    }

    public function testFirstPageOfAJoinedQuery(): void
    {
        $paginator = new Paginator($this->createPostQueryBuilder())->paginate(1);

        self::assertSame(1, $paginator->getCurrentPage());
        self::assertSame(Paginator::PAGE_SIZE, $paginator->getPageSize());
        self::assertSame($this->countPosts(), $paginator->getNumResults());
        self::assertCount(Paginator::PAGE_SIZE, iterator_to_array($paginator->getResults()));
        self::assertFalse($paginator->hasPreviousPage());
        self::assertTrue($paginator->hasNextPage());
        self::assertSame(1, $paginator->getPreviousPage());
        self::assertSame(2, $paginator->getNextPage());
        self::assertTrue($paginator->hasToPaginate());
    }

    public function testFirstPageOfAQueryWithoutJoins(): void
    {
        // a query without joins takes the "distinct disabled" branch of the count query
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Post::class, 'p')
            ->orderBy('p.publishedAt', 'DESC')
        ;

        $paginator = new Paginator($queryBuilder)->paginate(1);

        self::assertSame($this->countPosts(), $paginator->getNumResults());
        self::assertCount(Paginator::PAGE_SIZE, iterator_to_array($paginator->getResults()));
    }

    public function testResultsOfConsecutivePagesDoNotOverlap(): void
    {
        $firstPage = new Paginator($this->createPostQueryBuilder())->paginate(1);
        $secondPage = new Paginator($this->createPostQueryBuilder())->paginate(2);

        $idsOfFirstPage = $this->extractIds($firstPage);
        $idsOfSecondPage = $this->extractIds($secondPage);

        self::assertCount(Paginator::PAGE_SIZE, $idsOfFirstPage);
        self::assertCount(Paginator::PAGE_SIZE, $idsOfSecondPage);
        self::assertSame([], array_intersect($idsOfFirstPage, $idsOfSecondPage));
        self::assertSame(2, $secondPage->getCurrentPage());
        self::assertTrue($secondPage->hasPreviousPage());
        self::assertSame(1, $secondPage->getPreviousPage());
    }

    public function testLastPage(): void
    {
        $numResults = $this->countPosts();
        $lastPage = (int) ceil($numResults / Paginator::PAGE_SIZE);

        $paginator = new Paginator($this->createPostQueryBuilder())->paginate($lastPage);

        self::assertSame($lastPage, $paginator->getCurrentPage());
        self::assertSame($lastPage, $paginator->getLastPage());
        self::assertFalse($paginator->hasNextPage());
        self::assertSame($lastPage, $paginator->getNextPage());
        self::assertCount(
            $numResults - (($lastPage - 1) * Paginator::PAGE_SIZE),
            iterator_to_array($paginator->getResults())
        );
    }

    public function testPageBeyondTheLastOneReturnsNoResults(): void
    {
        $paginator = new Paginator($this->createPostQueryBuilder())->paginate(9999);

        self::assertSame(9999, $paginator->getCurrentPage());
        self::assertSame($this->countPosts(), $paginator->getNumResults());
        self::assertCount(0, iterator_to_array($paginator->getResults()));
        self::assertFalse($paginator->hasNextPage());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideNonPositivePages(): iterable
    {
        yield 'zero' => [0];
        yield 'minus one' => [-1];
        yield 'large negative' => [\PHP_INT_MIN + 1];
    }

    /**
     * Window::fromPageNumberAndSize() rejects page numbers below 1, so the
     * clamping done by paginate() must happen before the window is built.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('provideNonPositivePages')]
    public function testNonPositivePagesAreClampedToTheFirstPage(int $page): void
    {
        $paginator = new Paginator($this->createPostQueryBuilder())->paginate($page);

        self::assertSame(1, $paginator->getCurrentPage());
        self::assertFalse($paginator->hasPreviousPage());
        self::assertCount(Paginator::PAGE_SIZE, iterator_to_array($paginator->getResults()));
    }

    public function testCustomPageSize(): void
    {
        $paginator = new Paginator($this->createPostQueryBuilder(), 3)->paginate(2);

        self::assertSame(3, $paginator->getPageSize());
        self::assertCount(3, iterator_to_array($paginator->getResults()));
        self::assertSame((int) ceil($this->countPosts() / 3), $paginator->getLastPage());
    }

    public function testPageSizeOfOne(): void
    {
        $paginator = new Paginator($this->createPostQueryBuilder(), 1)->paginate(1);

        self::assertCount(1, iterator_to_array($paginator->getResults()));
        self::assertSame($this->countPosts(), $paginator->getLastPage());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideInvalidPageSizes(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-10];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('provideInvalidPageSizes')]
    public function testPageSizeBelowOneIsRejected(int $pageSize): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(\sprintf('The page size must be 1 or greater, "%d" given.', $pageSize));

        new Paginator($this->createPostQueryBuilder(), $pageSize);
    }

    public function testEmptyResultSet(): void
    {
        $queryBuilder = $this->createPostQueryBuilder()
            ->andWhere('p.title = :impossible')
            ->setParameter('impossible', '~ no post has this title ~')
        ;

        $paginator = new Paginator($queryBuilder)->paginate(1);

        self::assertSame(0, $paginator->getNumResults());
        self::assertSame(0, $paginator->getLastPage());
        self::assertCount(0, iterator_to_array($paginator->getResults()));
        self::assertFalse($paginator->hasNextPage());
        self::assertFalse($paginator->hasPreviousPage());
        self::assertFalse($paginator->hasToPaginate());
    }

    private function createPostQueryBuilder(): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->select('p', 'a', 't')
            ->from(Post::class, 'p')
            ->innerJoin('p.author', 'a')
            ->leftJoin('p.tags', 't')
            ->orderBy('p.publishedAt', 'DESC')
        ;
    }

    private function countPosts(): int
    {
        /** @var int $count */
        $count = $this->entityManager->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from(Post::class, 'p')
            ->getQuery()
            ->getSingleScalarResult()
        ;

        return (int) $count;
    }

    /**
     * @return list<int>
     */
    private function extractIds(Paginator $paginator): array
    {
        $ids = [];

        foreach ($paginator->getResults() as $post) {
            self::assertInstanceOf(Post::class, $post);
            $ids[] = (int) $post->getId();
        }

        return $ids;
    }
}