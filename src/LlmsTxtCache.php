<?php

declare(strict_types=1);

namespace Tomvondracek\LlmsTxt;

use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * Keeps rendered llms.txt bodies in the app cache, so a request doesn't render the
 * template and query the database again while nothing has changed.
 *
 * An entry lives as long as browsers and proxies may cache the response
 * (`max_age`), so the server is never staler than a shared cache already may be.
 * {@see EventListener\ContentChangeListener} drops all
 * entries as soon as content changes.
 */
final readonly class LlmsTxtCache
{
    public const TAG = 'llms_txt';

    public function __construct(
        private TagAwareCacheInterface $cache,
    ) {
    }

    /**
     * The cached body for `$key`, or the result of `$render`, kept for `$ttl`
     * seconds. Concurrent misses render once (the cache contracts lock).
     *
     * @param array<array-key, mixed> $key everything the body depends on
     * @param callable(): string $render
     */
    public function get(array $key, int $ttl, callable $render): string
    {
        if ($ttl < 1) {
            return $render();
        }

        return $this->cache->get(
            'llms_txt.' . hash('xxh128', serialize($key)),
            static function (ItemInterface $item) use ($ttl, $render): string {
                $item->expiresAfter($ttl);
                $item->tag(self::TAG);

                return $render();
            }
        );
    }

    public function invalidate(): void
    {
        $this->cache->invalidateTags([self::TAG]);
    }
}
