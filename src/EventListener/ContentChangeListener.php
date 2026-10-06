<?php

declare(strict_types=1);

namespace Tomvondracek\LlmsTxt\EventListener;

use Bolt\Entity\Content;
use Bolt\Entity\Field;
use Bolt\Entity\FieldTranslation;
use Bolt\Entity\Media;
use Bolt\Entity\Relation;
use Bolt\Entity\Taxonomy;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\PersistentCollection;
use Tomvondracek\LlmsTxt\LlmsTxtCache;

/**
 * Drops the cached llms.txt bodies once a flush has changed content: records,
 * their field values and translations, taxonomies, relations or media. Doctrine
 * events catch every way content changes (the editor, status changes, bulk
 * actions, the API), not only those Bolt dispatches events for.
 *
 * Not caught: Bolt's timed (de)publishing, a plain SQL update. That shows up when
 * the entry expires, after `max_age` at most.
 *
 * Registered for Doctrine's onFlush and postFlush in config/services.yaml.
 */
final class ContentChangeListener
{
    private const ENTITIES = [Content::class, Field::class, FieldTranslation::class, Taxonomy::class, Relation::class, Media::class];

    private bool $changed = false;

    public function __construct(
        private readonly LlmsTxtCache $cache,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $unitOfWork = $args->getObjectManager()->getUnitOfWork();

        $entities = [
            ...$unitOfWork->getScheduledEntityInsertions(),
            ...$unitOfWork->getScheduledEntityUpdates(),
            ...$unitOfWork->getScheduledEntityDeletions(),
            ...array_map(static fn (PersistentCollection $collection): ?object => $collection->getOwner(), [
                ...$unitOfWork->getScheduledCollectionUpdates(),
                ...$unitOfWork->getScheduledCollectionDeletions(),
            ]),
        ];

        foreach ($entities as $entity) {
            if ($this->isContent($entity)) {
                $this->changed = true;

                return;
            }
        }
    }

    /**
     * Only once the changes are in the database, so a request that renders right
     * after the invalidation reads the new content.
     */
    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->changed) {
            $this->changed = false;
            $this->cache->invalidate();
        }
    }

    private function isContent(?object $entity): bool
    {
        return array_any(self::ENTITIES, fn ($class): bool => $entity instanceof $class);
    }
}
