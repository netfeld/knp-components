<?php

namespace Test\Pager\Subscriber\Sortable\Doctrine\ORM;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Tools\SchemaTool;
use Knp\Component\Pager\ArgumentAccess\RequestArgumentAccess;
use Knp\Component\Pager\Event\Subscriber\Paginate\Doctrine\ORM\QuerySubscriber;
use Knp\Component\Pager\Event\Subscriber\Paginate\PaginationSubscriber;
use Knp\Component\Pager\Event\Subscriber\Sortable\Doctrine\ORM\QuerySubscriber as Sortable;
use Knp\Component\Pager\Pagination\SlidingPagination;
use Knp\Component\Pager\Paginator;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Test\Fixture\Entity\Article;
use Test\Tool\BaseTestCaseORM;

final class QueryTest extends BaseTestCaseORM
{
    /**
     * @test
     */
    public function shouldHandleApcQueryCache(): void
    {
        if (!\extension_loaded('apc') || !\ini_get('apc.enable_cli')) {
            $this->markTestSkipped('APC extension is not loaded.');
        }
        $config = new \Doctrine\ORM\Configuration();
        $config->setMetadataCacheImpl(new \Doctrine\Common\Cache\ApcCache);
        $config->setQueryCacheImpl(new \Doctrine\Common\Cache\ApcCache);
        $config->setProxyDir(__DIR__);
        $config->setProxyNamespace('Gedmo\Mapping\Proxy');
        $config->setAutoGenerateProxyClasses(false);
        $config->setMetadataDriverImpl($this->getMetadataDriverImplementation());

        $conn = [
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ];

        $em = EntityManager::create($conn, $config);
        $schema = \array_map(static function ($class) use ($em) {
            return $em->getClassMetadata($class);
        }, $this->getUsedEntityFixtures());

        $schemaTool = new SchemaTool($em);
        $schemaTool->dropSchema([]);
        $schemaTool->createSchema($schema);
        $this->populate($em);

        $query = $em->createQuery('SELECT a FROM Test\Fixture\Entity\Article a');

        $requestStack = $this->createRequestStack(['sort' => 'a.title', 'direction' => 'asc']);
        $p = $this->getPaginatorInstance($requestStack);
        $view = $p->paginate($query, 1, 10);

        $query = $em->createQuery('SELECT a FROM Test\Fixture\Entity\Article a');
        $view = $p->paginate($query, 1, 10);
    }

    /**
     * @test
     */
    public function shouldSortSimpleDoctrineQuery(): void
    {
        $em = $this->getMockSqliteEntityManager();
        $this->populate($em);

        $requestStack = $this->createRequestStack(['sort' => 'a.title', 'direction' => 'asc']);
        $accessor = new RequestArgumentAccess($requestStack);
        $dispatcher = new EventDispatcher;
        $dispatcher->addSubscriber(new PaginationSubscriber);
        $dispatcher->addSubscriber(new Sortable($accessor));
        $p = new Paginator($dispatcher, $accessor);

        $this->startQueryLog();
        $query = $this->em->createQuery('SELECT a FROM Test\Fixture\Entity\Article a');
        $query->setHint(QuerySubscriber::HINT_FETCH_JOIN_COLLECTION, false);
        $view = $p->paginate($query, 1, 10);

        $items = $view->getItems();
        $this->assertCount(4, $items);
        $this->assertEquals('autumn', $items[0]->getTitle());
        $this->assertEquals('spring', $items[1]->getTitle());
        $this->assertEquals('summer', $items[2]->getTitle());
        $this->assertEquals('winter', $items[3]->getTitle());

        $this->assertEquals(2, $this->queryAnalyzer->getNumExecutedQueries());
        $executed = $this->queryAnalyzer->getExecutedQueries();

        $this->assertEquals('SELECT a0_.id AS id_0, a0_.title AS title_1, a0_.enabled AS enabled_2 FROM Article a0_ ORDER BY a0_.title ASC LIMIT 10', $executed[1]);
    }

    /**
     * @test
     */
    public function shouldSortSimpleDoctrineQuery2(): void
    {
        $em = $this->getMockSqliteEntityManager();
        $this->populate($em);

        $requestStack = $this->createRequestStack(['sort' => 'a.title', 'direction' => 'desc']);
        $accessor = new RequestArgumentAccess($requestStack);
        $dispatcher = new EventDispatcher;
        $dispatcher->addSubscriber(new PaginationSubscriber);
        $dispatcher->addSubscriber(new Sortable($accessor));
        $p = new Paginator($dispatcher, $accessor);

        $this->startQueryLog();
        $query = $this->em->createQuery('SELECT a FROM Test\Fixture\Entity\Article a');
        $query->setHint(QuerySubscriber::HINT_FETCH_JOIN_COLLECTION, false);
        $view = $p->paginate($query);

        $items = $view->getItems();
        $this->assertCount(4, $items);
        $this->assertEquals('winter', $items[0]->getTitle());
        $this->assertEquals('summer', $items[1]->getTitle());
        $this->assertEquals('spring', $items[2]->getTitle());
        $this->assertEquals('autumn', $items[3]->getTitle());

        $this->assertEquals(2, $this->queryAnalyzer->getNumExecutedQueries());
        $executed = $this->queryAnalyzer->getExecutedQueries();

        $this->assertEquals('SELECT a0_.id AS id_0, a0_.title AS title_1, a0_.enabled AS enabled_2 FROM Article a0_ ORDER BY a0_.title DESC LIMIT 10', $executed[1]);
    }

    /**
     * @test
     */
    public function shouldValidateSortableParameters(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        $query = $this
            ->getMockSqliteEntityManager()
            ->createQuery('SELECT a FROM Test\Fixture\Entity\Article a')
        ;

        $requestStack = $this->createRequestStack(['sort' => '"a.title\'', 'direction' => 'asc']);
        $p = $this->getPaginatorInstance($requestStack);
        $view = $p->paginate($query, 1, 10);
    }

    /**
     * @test
     */
    public function shouldSortByAnyAvailableAlias(): void
    {
        $em = $this->getMockSqliteEntityManager();
        $this->populate($em);

        $dql = <<<___SQL
                    SELECT a, COUNT(a) AS counter
                    FROM Test\Fixture\Entity\Article a
            ___SQL;
        $query = $this->em->createQuery($dql);
        $query->setHint(QuerySubscriber::HINT_FETCH_JOIN_COLLECTION, false);

        $requestStack = $this->createRequestStack(['sort' => 'counter', 'direction' => 'asc']);
        $p = $this->getPaginatorInstance($requestStack);
        $this->startQueryLog();
        $view = $p->paginate($query, 1, 10, [PaginatorInterface::DISTINCT => false]);

        $this->assertEquals(2, $this->queryAnalyzer->getNumExecutedQueries());
        $executed = $this->queryAnalyzer->getExecutedQueries();

        $this->assertEquals('SELECT a0_.id AS id_0, a0_.title AS title_1, a0_.enabled AS enabled_2, COUNT(a0_.id) AS sclr_3 FROM Article a0_ ORDER BY sclr_3 ASC LIMIT 10', $executed[1]);
    }

    /**
     * @test
     */
    public function shouldWorkWithInitialPaginatorEventDispatcher(): void
    {
        $em = $this->getMockSqliteEntityManager();
        $this->populate($em);
        $query = $this
            ->em
            ->createQuery('SELECT a FROM Test\Fixture\Entity\Article a')
        ;
        $query->setHint(QuerySubscriber::HINT_FETCH_JOIN_COLLECTION, false);

        $requestStack = $this->createRequestStack(['sort' => 'a.title', 'direction' => 'asc']);
        $p = $this->getPaginatorInstance($requestStack);
        $this->startQueryLog();
        $view = $p->paginate($query, 1, 10);
        $this->assertInstanceOf(SlidingPagination::class, $view);

        $this->assertEquals(2, $this->queryAnalyzer->getNumExecutedQueries());
        $executed = $this->queryAnalyzer->getExecutedQueries();

        $this->assertEquals('SELECT a0_.id AS id_0, a0_.title AS title_1, a0_.enabled AS enabled_2 FROM Article a0_ ORDER BY a0_.title ASC LIMIT 10', $executed[1]);
    }

    /**
     * @test
     */
    public function shouldNotExecuteExtraQueriesWhenCountIsZero(): void
    {
        $query = $this
            ->getMockSqliteEntityManager()
            ->createQuery('SELECT a FROM Test\Fixture\Entity\Article a')
        ;

        $requestStack = $this->createRequestStack(['sort' => 'a.title', 'direction' => 'asc']);
        $p = $this->getPaginatorInstance($requestStack);
        $this->startQueryLog();
        $view = $p->paginate($query, 1, 10);
        $this->assertInstanceOf(SlidingPagination::class, $view);

        $this->assertEquals(2, $this->queryAnalyzer->getNumExecutedQueries());
    }

    /**
     * @test
     */
    public function shouldNotAcceptArrayParameter(): void
    {
        $this->expectException(\PHP_VERSION_ID < 80100 ? \TypeError::class : \UnexpectedValueException::class);
        $query = $this
            ->getMockSqliteEntityManager()
            ->createQuery('SELECT a FROM Test\Fixture\Entity\Article a')
        ;
        $requestStack = $this->createRequestStack(['sort' => ['field' => 'a.name'], 'direction' => 'asc']);
        $accessor = new RequestArgumentAccess($requestStack);
        $dispatcher = new EventDispatcher;
        $dispatcher->addSubscriber(new PaginationSubscriber);
        $dispatcher->addSubscriber(new Sortable($accessor));
        $p = new Paginator($dispatcher, $accessor);
        $p->paginate($query, 1, 10);
    }

    protected function getUsedEntityFixtures(): array
    {
        return [Article::class];
    }

    private function populate($em): void
    {
        $summer = new Article;
        $summer->setTitle('summer');

        $winter = new Article;
        $winter->setTitle('winter');

        $autumn = new Article;
        $autumn->setTitle('autumn');

        $spring = new Article;
        $spring->setTitle('spring');

        $em->persist($summer);
        $em->persist($winter);
        $em->persist($autumn);
        $em->persist($spring);
        $em->flush();
    }

    private function getApcEntityManager(): EntityManager
    {
        $config = new \Doctrine\ORM\Configuration();
        $config->setMetadataCacheImpl(new \Doctrine\Common\Cache\ApcCache);
        $config->setQueryCacheImpl(new \Doctrine\Common\Cache\ApcCache);
        $config->setProxyDir(__DIR__);
        $config->setProxyNamespace('Gedmo\Mapping\Proxy');
        $config->setAutoGenerateProxyClasses(false);
        $config->setMetadataDriverImpl($this->getMetadataDriverImplementation());

        $conn = [
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ];

        return EntityManager::create($conn, $config);
    }
}
