<?php

declare(strict_types=1);

namespace Tomvondracek\LlmsTxt\Tests;

use Bolt\Canonical;
use Bolt\Twig\TokenParser\SetcontentTokenParser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Tomvondracek\LlmsTxt\LlmsTxtResponse;
use Tomvondracek\LlmsTxt\SiteUrl;
use Tomvondracek\LlmsTxt\Tests\Fixtures\FakeRecord;
use Tomvondracek\LlmsTxt\Twig\HtmlToTextExtension;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Source;
use Twig\TwigFilter;

/**
 * Renders templates/llms.txt.twig with stand-ins for Bolt's records, filters and
 * `setcontent` tag.
 */
final class ShippedTemplateTest extends TestCase
{
    private const SETCONTENT = '{% setcontent records = contentType.slug limit 50 page 1 %}';

    public function testCompilesWithBoltsSetcontentTag(): void
    {
        $twig = $this->twig([]);
        $twig->addTokenParser(new SetcontentTokenParser());

        $twig->compileSource(new Source($this->source(), 'llms.txt.twig'));

        $this->addToAssertionCount(1);
    }

    public function testRendersSiteAndRecords(): void
    {
        $body = LlmsTxtResponse::normalize($this->twig([
            'pages' => [
                new FakeRecord('About [us]', 'https://example.com/about', [
                    'title' => ['text', 'About'],
                    'teaser' => ['textarea', ''],
                    'body' => ['html', '<p>We make <b>things</b>.</p><p>Since 1990.</p>'],
                    'intro' => ['html', '<p>Not used: the body comes first.</p>'],
                ]),
                new FakeRecord('Contact', 'https://example.com/contact', [
                    'title' => ['text', 'Contact'],
                    'image' => ['image', 'x.png'],
                ]),
            ],
            'entries' => [],
        ])->render('llms.txt.twig', [
            'baseUrl' => 'https://example.com',
        ]));

        self::assertSame(<<<'TXT'
            # Kuřim & Co.

            > Things since 1990

            This file is generated from the content management system of https://example.com, so it lists what the website shows.

            ## Website

            - [Homepage](https://example.com/): the home page of Kuřim & Co.

            ## Pages

            - [About (us)](https://example.com/about): We make things. Since 1990.
            - [Contact](https://example.com/contact)

            TXT
            , $body);
    }

    private function source(): string
    {
        $source = file_get_contents(dirname(__DIR__) . '/templates/llms.txt.twig');
        self::assertIsString($source);
        self::assertStringContainsString(self::SETCONTENT, $source);

        return $source;
    }

    /**
     * @param array<string, list<FakeRecord>> $records per ContentType slug
     */
    private function twig(array $records): Environment
    {
        $source = str_replace(self::SETCONTENT, '{% set records = fixtures[contentType.slug] ?? [] %}', $this->source());

        $twig = new Environment(new ArrayLoader([
            'llms.txt.twig' => $source,
        ]), [
            'strict_variables' => true,
        ]);

        $requestStack = new RequestStack([Request::create('https://example.com/llms.txt')]);
        $twig->addExtension(new HtmlToTextExtension(new SiteUrl(self::createStub(Canonical::class), $requestStack)));
        $twig->addFilter(new TwigFilter('title_fields_names', static fn (FakeRecord $record): array => ['title']));
        $twig->addFilter(new TwigFilter('title', static fn (FakeRecord $record): string => $record->title));
        $twig->addFilter(new TwigFilter('link', static fn (FakeRecord $record, bool $absolute = false): string => $record->link));

        $twig->addGlobal('fixtures', $records);
        $twig->addGlobal('app', [
            'request' => [
                'host' => 'example.com',
            ],
        ]);
        $twig->addGlobal('config', new class() {
            public function get(string $path): mixed
            {
                return match ($path) {
                    'general/sitename' => 'Kuřim &amp; Co.',
                    'general/payoff' => '<em>Things</em> since 1990',
                    'contenttypes' => [
                        [
                            'slug' => 'homepage',
                            'name' => 'Homepage',
                            'singleton' => true,
                            'viewless' => false,
                        ],
                        [
                            'slug' => 'pages',
                            'name' => 'Pages',
                            'singleton' => false,
                            'viewless' => false,
                        ],
                        [
                            'slug' => 'entries',
                            'name' => 'Entries',
                            'singleton' => false,
                            'viewless' => false,
                        ],
                        [
                            'slug' => 'blocks',
                            'name' => 'Blocks',
                            'singleton' => false,
                            'viewless' => true,
                        ],
                    ],
                    default => null,
                };
            }
        });

        return $twig;
    }
}
