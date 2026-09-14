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
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Tests for the application paginator that wraps the Doctrine paginator.
 */
final class PaginatorTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * Regression test for the deprecation of Doctrine\ORM\Tools\Pagination\Paginator
     * (deprecated in doctrine/orm 3.7, removed in 4.0).
     *
     * The docblock deprecation is invisible at runtime, so instead of waiting for
     * a fatal error on the next major we assert that none of the Doctrine classes
     * this paginator builds upon is marked as deprecated.
     */
    public function testItDoesNotBuildOnDeprecatedDoctrineClasses(): void
    {
        $imports = self::importedClassesOfAppPaginator();

        self::assertNotContains(
            \Doctrine\ORM\Tools\Pagination\Paginator::class,
            $imports,
            'The deprecated Doctrine paginator must not be used anymore.'
        );

        foreach ($imports as $import) {
            if (!class_exists($import) && !interface_exists($import) && !trait_exists($import)) {
                continue;
            }

            $docComment = new \ReflectionClass($import)->getDocComment();

            self::assertFalse(
                \is_string($docComment) && str_contains($docComment, '@deprecated'),
                \sprintf('App\Pagination\Paginator must not depend on the deprecated class "%s".', $import)
            );
        }
    }

    public function testFirstPage(): void
    {
        $total = $this->countPosts();
        $paginator = new Paginator($this->createPostQueryBuilder())->paginate(1);

        self::assertSame(1, $paginator->getCurrentPage());
        self::assertSame(Paginator::PAGE_SIZE, $paginator->getPageSize());
        self::assertSame($total, $paginator->getNumResults());
        self::assertSame((int) ceil($total / Paginator::PAGE_SIZE), $paginator->getLastPage());
        self::assertCount(Paginator::PAGE_SIZE, iterator_to_array($paginator->getResults()));
        self::assertFalse($paginator->hasPreviousPage());
        self::assertSame(1, $paginator->getPreviousPage());
        self::assertTrue($paginator->hasNextPage());
        self::assertSame(2, $paginator->getNextPage());
        self::assertTrue($paginator->hasToPaginate());
    }

    public function testLastPage(): void
    {
        $total = $this->countPosts();
        $lastPage = (int) ceil($total / Paginator::PAGE_SIZE);
        $expectedOnLastPage = $total - (($lastPage - 1) * Paginator::PAGE_SIZE);

        $paginator = new Paginator($this->createPostQueryBuilder())->paginate($lastPage);

        self::assertSame($lastPage, $paginator->getCurrentPage());
        self::assertCount($expectedOnLastPage, iterator_to_array($paginator->getResults()));
        self::assertTrue($paginator->hasPreviousPage());
        self::assertSame($lastPage - 1, $paginator->getPreviousPage());
        self::assertFalse($paginator->hasNextPage());
        self::assertSame($lastPage, $paginator->getNextPage());
    }

    public function testPagesDoNotOverlap(): void
    {
        $firstPageIds = $this->idsOf(new Paginator($this->createPostQueryBuilder())->paginate(1));
        $secondPageIds = $this->idsOf(new Paginator($this->createPostQueryBuilder())->paginate(2));

        self::assertCount(Paginator::PAGE_SIZE, $firstPageIds);
        self::assertCount(Paginator::PAGE_SIZE, $secondPageIds);
        self::assertSame([], array_intersect($firstPageIds, $secondPageIds));
    }

    public function testPageBeyondTheLastOneIsEmptyButKeepsTheTotal(): void
    {
        $total = $this->countPosts();
        $paginator = new Paginator($this->createPostQueryBuilder())->paginate(9999);

        self::assertSame(9999, $paginator->getCurrentPage());
        self::assertSame($total, $paginator->getNumResults());
        self::assertCount(0, iterator_to_array($paginator->getResults()));
        self::assertFalse($paginator->hasNextPage());
        self::assertTrue($paginator->hasPreviousPage());
    }

    /**
     * Doctrine's Window value object rejects a negative offset, so the paginator
     * must keep clamping the requested page to 1.
     */
    #[DataProvider('provideNonPositivePages')]
    public function testNonPositivePageIsClampedToTheFirstPage(int $page): void
    {
        $paginator = new Paginator($this->createPostQueryBuilder())->paginate($page);

        self::assertSame(1, $paginator->getCurrentPage());
        self::assertSame(
            $this->idsOf(new Paginator($this->createPostQueryBuilder())->paginate(1)),
            $this->idsOf($paginator)
        );
    }

    /**
     * @return iterable<array{int}>
     */
    public static function provideNonPositivePages(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'very negative' => [\PHP_INT_MIN + 1];
    }

    public function testEmptyResultSet(): void
    {
        $queryBuilder = $this->createPostQueryBuilder()
            ->andWhere('p.id = :missingId')
            ->setParameter('missingId', -1);

        $paginator = new Paginator($queryBuilder)->paginate(1);

        self::assertSame(0, $paginator->getNumResults());
        self::assertSame(0, $paginator->getLastPage());
        self::assertCount(0, iterator_to_array($paginator->getResults()));
        self::assertFalse($paginator->hasToPaginate());
        self::assertFalse($paginator->hasNextPage());
        self::assertFalse($paginator->hasPreviousPage());
    }

    public function testCustomPageSize(): void
    {
        $total = $this->countPosts();
        $pageSize = 7;
        $lastPage = (int) ceil($total / $pageSize);

        $paginator = new Paginator($this->createPostQueryBuilder(), $pageSize)->paginate(1);

        self::assertSame($pageSize, $paginator->getPageSize());
        self::assertSame($lastPage, $paginator->getLastPage());
        self::assertCount($pageSize, iterator_to_array($paginator->getResults()));

        $lastPagePaginator = new Paginator($this->createPostQueryBuilder(), $pageSize)->paginate($lastPage);

        self::assertCount($total - (($lastPage - 1) * $pageSize), iterator_to_array($lastPagePaginator->getResults()));
    }

    public function testSinglePageResultDoesNotHaveToPaginate(): void
    {
        $paginator = new Paginator($this->createPostQueryBuilder(), 1000)->paginate(1);

        self::assertFalse($paginator->hasToPaginate());
        self::assertSame(1, $paginator->getLastPage());
        self::assertCount($this->countPosts(), iterator_to_array($paginator->getResults()));
    }

    /**
     * A page size below 1 used to blow up with a division by zero in getLastPage();
     * Doctrine's Window rejects it too, so it is refused up front.
     */
    #[DataProvider('provideInvalidPageSizes')]
    public function testPageSizeBelowOneIsRejected(int $pageSize): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(\sprintf('The page size must be 1 or more, "%d" given.', $pageSize));

        new Paginator($this->createPostQueryBuilder(), $pageSize);
    }

    /**
     * @return iterable<array{int}>
     */
    public static function provideInvalidPageSizes(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    /**
     * Posts fetch-join a to-many collection (tags), so the paginator has to fall
     * back to the identifier subquery to avoid returning duplicated root entities.
     */
    public function testFetchJoinedCollectionReturnsDistinctRootEntities(): void
    {
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->addSelect('a', 't')
            ->from(Post::class, 'p')
            ->innerJoin('p.author', 'a')
            ->leftJoin('p.tags', 't')
            ->orderBy('p.publishedAt', 'DESC');

        $paginator = new Paginator($queryBuilder)->paginate(1);
        $ids = $this->idsOf($paginator);

        self::assertCount(Paginator::PAGE_SIZE, $ids);
        self::assertSame($ids, array_values(array_unique($ids)));
        self::assertSame($this->countPosts(), $paginator->getNumResults());
    }

    private function createPostQueryBuilder(): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Post::class, 'p')
            ->orderBy('p.id', 'ASC');
    }

    private function countPosts(): int
    {
        return (int) $this->entityManager
            ->createQuery('SELECT COUNT(p.id) FROM '.Post::class.' p')
            ->getSingleScalarResult();
    }

    /**
     * @return list<int|null>
     */
    private function idsOf(Paginator $paginator): array
    {
        $ids = [];

        foreach ($paginator->getResults() as $post) {
            self::assertInstanceOf(Post::class, $post);

            $ids[] = $post->getId();
        }

        return $ids;
    }

    /**
     * Returns the fully qualified names imported by App\Pagination\Paginator.
     *
     * @return list<string>
     */
    private static function importedClassesOfAppPaginator(): array
    {
        $fileName = new \ReflectionClass(Paginator::class)->getFileName();
        self::assertIsString($fileName);

        $source = file_get_contents($fileName);
        self::assertIsString($source);

        preg_match_all('/^use\s+(?!function\s|const\s)([^\s;]+)(?:\s+as\s+\S+)?\s*;/m', $source, $matches);

        self::assertNotEmpty($matches[1], 'Unable to read the imports of App\Pagination\Paginator.');

        return $matches[1];
    }
}