<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Tests\DependencyInjection;

use Golpilolz\CsvOm\DependencyInjection\GolpilolzCsvOmExtension;
use Golpilolz\CsvOm\Exception\MappingException;
use Golpilolz\CsvOm\GolpilolzCsvOmBundle;
use Golpilolz\CsvOm\Om\ObjectManagerInterface;
use Golpilolz\CsvOm\Om\Repository\CSVRepository;
use Golpilolz\CsvOm\Tests\CsvDirectoryTrait;
use Golpilolz\CsvOm\Tests\Fixtures\Entity\Author;
use Golpilolz\CsvOm\Tests\Fixtures\Entity\AuthorRepository;
use Golpilolz\CsvOm\Tests\Fixtures\Entity\ProductCategory;
use Golpilolz\CsvOm\Tests\Fixtures\Entity\Publisher;
use Golpilolz\CsvOm\Tests\Fixtures\Entity\PublisherRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Compiles a real container the way a Symfony app does: bundle config, then autowired and
 * autoconfigured application repositories.
 */
final class ContainerIntegrationTest extends TestCase
{
    use CsvDirectoryTrait;

    protected function setUp(): void
    {
        $this->createCsvDirectory();
    }

    protected function tearDown(): void
    {
        $this->removeCsvDirectory();
    }

    private function container(array $config, array $repositories = [AuthorRepository::class]): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', $this->dir);

        $bundle = new GolpilolzCsvOmBundle();
        $container->registerExtension($bundle->getContainerExtension());
        $container->loadFromExtension('golpilolz_csv_om', $config);
        $bundle->build($container);

        foreach ($repositories as $class) {
            $container->register($class, $class)->setAutowired(true)->setAutoconfigured(true)->setPublic(true);
        }
        // The extension only runs during compile(): expose the object manager through an alias resolved then.
        $container->setAlias('test.object_manager', ObjectManagerInterface::class)->setPublic(true);

        $container->compile();

        return $container;
    }

    public function testServiceRepositoryIsReturnedByGetRepository(): void
    {
        $container = $this->container([]);
        $om = $container->get('test.object_manager');

        self::assertSame($container->get(AuthorRepository::class), $om->getRepository(Author::class));
        self::assertSame($om, $container->get('golpilolz_csv_om.object_manager'));
    }

    public function testEntityWithoutServiceRepositoryFallsBackToInstantiation(): void
    {
        $om = $this->container([])->get('test.object_manager');

        self::assertInstanceOf(CSVRepository::class, $om->getRepository(ProductCategory::class));
    }

    public function testDefaultDirectoryIsProjectCsvDir(): void
    {
        mkdir($this->dir . '/csv');
        $om = $this->container([])->get('test.object_manager');

        $om->persist(new Author(1, 'Ursula'));
        $om->flush();

        self::assertFileExists($this->dir . '/csv/author.csv');
    }

    public function testConfiguredDirectoryAndDialectAreUsed(): void
    {
        mkdir($this->dir . '/data');
        $om = $this->container(['directory' => $this->dir . '/data', 'delimiter' => ';'])->get('test.object_manager');

        $om->persist(new Author(1, 'Le Guin, Ursula'));
        $om->flush();

        self::assertSame("id;birth_date;priority;name\n1;;;\"Le Guin, Ursula\"\n", file_get_contents($this->dir . '/data/author.csv'));
    }

    public function testRepositoryDeclaringAnotherEntityIsRejected(): void
    {
        $om = $this->container([], [AuthorRepository::class, PublisherRepository::class])->get('test.object_manager');

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('is configured for entity "Golpilolz\CsvOm\Tests\Fixtures\Entity\Book" but is used by "' . Publisher::class . '"');

        $om->getRepository(Publisher::class);
    }
}
