<?php

declare(strict_types=1);

namespace Tomvondracek\LlmsTxt\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Tomvondracek\LlmsTxt\LlmsTxtCache;

final class LlmsTxtCacheTest extends TestCase
{
    private ArrayAdapter $pool;
    private LlmsTxtCache $cache;
    private int $renders = 0;

    protected function setUp(): void
    {
        $this->pool = new ArrayAdapter();
        $this->cache = new LlmsTxtCache(new TagAwareAdapter($this->pool));
    }

    public function testRendersOncePerKey(): void
    {
        self::assertSame('body 1', $this->cache->get(['a'], 60, $this->render(...)));
        self::assertSame('body 1', $this->cache->get(['a'], 60, $this->render(...)));
        self::assertSame('body 2', $this->cache->get(['b'], 60, $this->render(...)), 'another key, another entry');
        self::assertSame(2, $this->renders);
    }

    public function testEntryExpiresAfterTheTtl(): void
    {
        $this->cache->get(['a'], 600, $this->render(...));

        $expiries = (new ReflectionProperty(ArrayAdapter::class, 'expiries'))->getValue($this->pool);
        self::assertIsArray($expiries);
        $bodyExpiries = array_filter($expiries, static fn (mixed $expiry): bool => $expiry !== PHP_INT_MAX);
        self::assertCount(1, $bodyExpiries, 'the body expires, the tag version does not');
        self::assertEqualsWithDelta(microtime(true) + 600, reset($bodyExpiries), 5);
    }

    public function testZeroTtlRendersEveryTime(): void
    {
        $this->cache->get(['a'], 0, $this->render(...));
        $this->cache->get(['a'], 0, $this->render(...));

        self::assertSame(2, $this->renders);
        self::assertSame([], $this->pool->getValues(), 'nothing stored');
    }

    public function testInvalidateDropsAllEntries(): void
    {
        $this->cache->get(['a'], 60, $this->render(...));
        $this->cache->get(['b'], 60, $this->render(...));

        $this->cache->invalidate();

        self::assertSame('body 3', $this->cache->get(['a'], 60, $this->render(...)));
        self::assertSame('body 4', $this->cache->get(['b'], 60, $this->render(...)));
    }

    private function render(): string
    {
        return 'body ' . ++$this->renders;
    }
}
