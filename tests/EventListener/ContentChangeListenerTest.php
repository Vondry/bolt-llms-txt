<?php

declare(strict_types=1);

namespace Tomvondracek\LlmsTxt\Tests\EventListener;

use Bolt\Entity\Content;
use Bolt\Entity\Field;
use Bolt\Entity\Field\HtmlField;
use Bolt\Entity\FieldTranslation;
use Bolt\Entity\Log;
use Bolt\Entity\Media;
use Bolt\Entity\Relation;
use Bolt\Entity\Taxonomy;
use Bolt\Entity\User;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ToManyAssociationMapping;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Tomvondracek\LlmsTxt\EventListener\ContentChangeListener;
use Tomvondracek\LlmsTxt\LlmsTxtCache;

final class ContentChangeListenerTest extends TestCase
{
    private LlmsTxtCache $cache;

    protected function setUp(): void
    {
        $this->cache = new LlmsTxtCache(new TagAwareAdapter(new ArrayAdapter()));
        $this->cache->get(['key'], 60, static fn (): string => 'cached');
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function contentEntities(): iterable
    {
        foreach ([Content::class, Field::class, HtmlField::class, FieldTranslation::class, Taxonomy::class, Relation::class, Media::class] as $class) {
            yield $class => [$class];
        }
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('contentEntities')]
    public function testContentChangeInvalidatesAfterTheFlush(string $class): void
    {
        $listener = new ContentChangeListener($this->cache);

        $listener->onFlush($this->onFlush(updates: [$this->entity($class)]));
        self::assertSame('cached', $this->cached(), 'not before the changes are written');

        $listener->postFlush(new PostFlushEventArgs(self::createStub(EntityManagerInterface::class)));
        self::assertSame('fresh', $this->cached());
    }

    public function testInsertionsAndDeletionsCount(): void
    {
        $this->flush(insertions: [$this->entity(Content::class)]);
        self::assertSame('fresh', $this->cached());

        $this->cache->get(['key'], 60, static fn (): string => 'cached again');
        $this->flush(deletions: [$this->entity(Content::class)]);
        self::assertSame('fresh', $this->cached());
    }

    public function testCollectionChangeOfARecordCounts(): void
    {
        $this->flush(collectionUpdates: [$this->collectionOf($this->entity(Content::class))]);

        self::assertSame('fresh', $this->cached());
    }

    public function testOtherEntitiesDontCount(): void
    {
        $this->flush(updates: [$this->entity(User::class), $this->entity(Log::class)], collectionDeletions: [$this->collectionOf($this->entity(User::class))]);

        self::assertSame('cached', $this->cached());
    }

    public function testOnlyTheFlushWithChangesInvalidates(): void
    {
        $listener = new ContentChangeListener($this->cache);
        $listener->onFlush($this->onFlush(updates: [$this->entity(Content::class)]));
        $listener->postFlush(new PostFlushEventArgs(self::createStub(EntityManagerInterface::class)));

        $this->cache->get(['key'], 60, static fn (): string => 'cached again');
        $listener->onFlush($this->onFlush(updates: [$this->entity(User::class)]));
        $listener->postFlush(new PostFlushEventArgs(self::createStub(EntityManagerInterface::class)));

        self::assertSame('cached again', $this->cached());
    }

    /**
     * @param list<object> $insertions
     * @param list<object> $updates
     * @param list<object> $deletions
     * @param list<PersistentCollection<array-key, object>> $collectionUpdates
     * @param list<PersistentCollection<array-key, object>> $collectionDeletions
     */
    private function flush(array $insertions = [], array $updates = [], array $deletions = [], array $collectionUpdates = [], array $collectionDeletions = []): void
    {
        $listener = new ContentChangeListener($this->cache);
        $listener->onFlush($this->onFlush($insertions, $updates, $deletions, $collectionUpdates, $collectionDeletions));
        $listener->postFlush(new PostFlushEventArgs(self::createStub(EntityManagerInterface::class)));
    }

    /**
     * @param list<object> $insertions
     * @param list<object> $updates
     * @param list<object> $deletions
     * @param list<PersistentCollection<array-key, object>> $collectionUpdates
     * @param list<PersistentCollection<array-key, object>> $collectionDeletions
     */
    private function onFlush(array $insertions = [], array $updates = [], array $deletions = [], array $collectionUpdates = [], array $collectionDeletions = []): OnFlushEventArgs
    {
        $unitOfWork = self::createStub(UnitOfWork::class);
        $unitOfWork->method('getScheduledEntityInsertions')
            ->willReturn($insertions);
        $unitOfWork->method('getScheduledEntityUpdates')
            ->willReturn($updates);
        $unitOfWork->method('getScheduledEntityDeletions')
            ->willReturn($deletions);
        $unitOfWork->method('getScheduledCollectionUpdates')
            ->willReturn($collectionUpdates);
        $unitOfWork->method('getScheduledCollectionDeletions')
            ->willReturn($collectionDeletions);

        $entityManager = self::createStub(EntityManagerInterface::class);
        $entityManager->method('getUnitOfWork')
            ->willReturn($unitOfWork);

        return new OnFlushEventArgs($entityManager);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function entity(string $class): object
    {
        return (new ReflectionClass($class))->newInstanceWithoutConstructor();
    }

    /**
     * @return PersistentCollection<array-key, object>
     */
    private function collectionOf(object $owner): PersistentCollection
    {
        $metadata = new ClassMetadata($owner::class);
        $metadata->mapManyToMany([
            'fieldName' => 'items',
            'targetEntity' => Taxonomy::class,
        ]);

        $collection = new PersistentCollection(self::createStub(EntityManagerInterface::class), new ClassMetadata(Taxonomy::class), new ArrayCollection());
        $mapping = $metadata->getAssociationMapping('items');
        self::assertInstanceOf(ToManyAssociationMapping::class, $mapping);
        $collection->setOwner($owner, $mapping);

        return $collection;
    }

    private function cached(): string
    {
        return $this->cache->get(['key'], 60, static fn (): string => 'fresh');
    }
}
