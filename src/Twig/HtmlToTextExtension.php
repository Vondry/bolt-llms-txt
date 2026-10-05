<?php

declare(strict_types=1);

namespace Tomvondracek\LlmsTxt\Twig;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use Stringable;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Turns field HTML into single-line plain text for non-HTML outputs such as llms.txt.
 * Links are kept as Markdown links with absolute URLs, so their targets survive.
 *
 * Filters:
 *  - `html_to_text`: rich text or any field value as one line of plain text; with a maximum
 *    length, shortened at a word boundary (links then keep only their text, so no link is cut)
 *  - `markdown_label`: the same, safe as the [label] of a Markdown link
 *  - `markdown_url`: a URL field as an absolute URL for the (url) of a Markdown link, or ''
 */
final class HtmlToTextExtension extends AbstractExtension
{
    /**
     * Elements whose content is never visible text.
     */
    private const SKIPPED = ['script', 'style', 'template', 'iframe', 'object', 'noscript', 'head'];

    /**
     * Elements that separate words, so their content must not merge with its neighbours. The text is
     * single-line, so the value is the punctuation put after the element's text when more text follows
     * and it has no punctuation of its own — otherwise `<li>Adults 600 CZK</li><li>Kids free</li>` would
     * read "Adults 600 CZK Kids free". Nothing is added at the very end, so a one-paragraph title stays as-is.
     */
    private const BLOCKS = [
        'p' => '.', 'div' => '.', 'h1' => ':', 'h2' => ':', 'h3' => ':', 'h4' => ':', 'h5' => ':', 'h6' => ':',
        'ul' => '.', 'ol' => '.', 'li' => ';', 'dl' => '.', 'dt' => ':', 'dd' => ';',
        'table' => '.', 'tr' => '.', 'td' => ';', 'th' => ';', 'blockquote' => '.', 'pre' => '.',
        'figure' => '.', 'figcaption' => '.', 'section' => '.', 'article' => '.', 'address' => '.',
        'hr' => '.',
    ];

    /**
     * Text that already ends a sentence or clause, optionally followed by closing quotes or brackets.
     * \p{Pi} too, because Czech closes quotes with “ (an "initial" quote in Unicode): „Ahoj.“
     */
    private const TERMINATED = '/[.!?…:;,][\p{Pi}\p{Pf}\p{Pe}"\']*$/u';

    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('html_to_text', $this->htmlToText(...)),
            new TwigFilter('markdown_label', $this->markdownLabel(...)),
            new TwigFilter('markdown_url', $this->markdownUrl(...)),
        ];
    }

    /**
     * Accepts anything Twig may hand over (strings, Twig Markup, Bolt fields). Text-type
     * Bolt fields are entity-encoded by Bolt's sanitiser too, so they need this as well.
     */
    public function htmlToText(mixed $html, ?int $maxLength = null): string
    {
        $html = is_scalar($html) || $html instanceof Stringable ? (string) $html : '';
        if (mb_trim($html) === '') {
            return '';
        }

        $document = new DOMDocument();
        $useInternalErrors = libxml_use_internal_errors(true);
        // The XML declaration makes libxml read the fragment as UTF-8 instead of Latin-1.
        $document->loadHTML('<?xml encoding="UTF-8"?><body>' . $html . '</body>', LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($useInternalErrors);

        $body = $document->getElementsByTagName('body')
            ->item(0);

        if ($body === null) {
            return '';
        }

        if ($maxLength === null) {
            return $this->collapse($this->nodeText($body, true));
        }

        return $this->shorten($this->collapse($this->nodeText($body, false)), $maxLength);
    }

    /**
     * Like html_to_text, made safe for the [label] part of a Markdown link. Takes the raw field:
     * passing text that html_to_text already decoded would decode entities twice (`&lt;` → tag).
     */
    public function markdownLabel(mixed $text): string
    {
        return $this->label($this->htmlToText($text));
    }

    /**
     * Turns a URL field (raw, so entities are decoded once) into an absolute URL usable as the (url)
     * part of a Markdown link, or '' if it is not a link a reader can follow. Unlike links inside
     * rich text, a bare host such as `www.example.com` counts as a web address here: URL fields are
     * typed in by editors, who often leave out the scheme.
     */
    public function markdownUrl(mixed $url): string
    {
        return $this->absoluteUrl($this->htmlToText($url), true) ?? '';
    }

    private function nodeText(DOMNode $node, bool $links): string
    {
        $text = '';
        // Punctuation owed to the block (or line break) that ended last, added once more text follows.
        $pending = null;

        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                $part = $child->data;
            } elseif ($child instanceof DOMElement) {
                $tag = mb_strtolower($child->tagName);
                if (in_array($tag, self::SKIPPED, true)) {
                    continue;
                }

                if ($tag === 'br') {
                    // A line break inside a paragraph ends the line's clause, like a list item.
                    $pending ??= ';';
                    $text .= ' ';

                    continue;
                }

                // An image's alt text is the text a reader would get instead of the picture.
                $part = $tag === 'img' ? $child->getAttribute('alt') : $this->nodeText($child, $links);

                if ($tag === 'a' && $links) {
                    $part = $this->markdownLink($child->getAttribute('href'), $part);
                } elseif (isset(self::BLOCKS[$tag])) {
                    $text .= ' ';
                    if ($this->collapse($part) !== '') {
                        $text = $this->terminate($text, $pending ?? ';') . ' ' . $part . ' ';
                        $pending = self::BLOCKS[$tag];
                    } elseif ($tag === 'hr') {
                        // A rule has no text of its own but still separates what is around it.
                        $pending ??= self::BLOCKS[$tag];
                    }

                    continue;
                }
            } else {
                continue;
            }

            if ($pending !== null && $this->collapse($part) !== '') {
                // Text that starts with its own punctuation (`<p>A</p>, more`) needs no extra mark.
                $text = preg_match('/^\s*[.,;:!?…)\]]/u', $part) === 1 ? mb_rtrim($text) : $this->terminate($text, $pending) . ' ';
                $pending = null;
            }
            $text .= $part;
        }

        return $text;
    }

    private function markdownLink(string $href, string $innerText): string
    {
        $text = $this->collapse($innerText);
        $href = mb_trim($href);

        // `mailto:x@y.cz` labelled `x@y.cz` (or `tel:` labelled with the number) reads fine as-is.
        if (preg_match('~^(mailto|tel):(.*)$~i', $href, $match) === 1 && $this->collapse($match[2]) === $text) {
            return $text;
        }

        $href = $this->absoluteUrl($href, false);
        if ($href === null) {
            return $text;
        }

        return $text === '' ? '<' . $href . '>' : sprintf('[%s](%s)', $this->label($text), $href);
    }

    /**
     * Absolute, Markdown-safe form of `$href`, or null when there is no page it could point to.
     */
    private function absoluteUrl(string $href, bool $bareHostIsWeb): ?string
    {
        $href = mb_trim($href);

        if (preg_match('~^([a-z][a-z0-9+.-]*):~i', $href, $match) === 1) {
            // Anything but these (javascript:, data:, …) is not a link a reader can follow.
            if (! in_array(mb_strtolower($match[1]), ['http', 'https', 'mailto', 'tel'], true)) {
                return null;
            }
        } elseif (str_starts_with($href, '/')) {
            // Site-relative links are useless outside the page, so make them absolute.
            $request = $this->requestStack->getCurrentRequest();
            if ($request === null) {
                return null;
            }
            $href = (str_starts_with($href, '//') ? $request->getScheme() . ':' : $request->getSchemeAndHttpHost()) . $href;
        } elseif ($bareHostIsWeb && preg_match('~^[a-z0-9-]+(\.[a-z0-9-]+)+([/?#].*)?$~i', $href) === 1) {
            $href = 'https://' . $href;
        } else {
            // Empty, fragment-only (#anchor) or page-relative: there is no page to resolve it against.
            return null;
        }

        return str_replace([' ', '(', ')'], ['%20', '%28', '%29'], $href);
    }

    /**
     * Makes text safe as the [label] of a Markdown link. Square brackets become round ones rather than
     * being backslash-escaped, because simple llms.txt parsers match the label as `\[[^\]]+\]` and
     * would keep the backslashes. With no brackets left, a backslash cannot break the label either.
     */
    private function label(string $text): string
    {
        return strtr($text, ['[' => '(', ']' => ')']);
    }

    /**
     * Ends text with `$punctuation` unless it is empty or already ends with punctuation.
     */
    private function terminate(string $text, string $punctuation): string
    {
        $text = $this->collapse($text);

        return $text === '' || preg_match(self::TERMINATED, $text) === 1 ? $text : $text . $punctuation;
    }

    /**
     * Cuts text longer than `$maxLength` characters at the last word boundary and
     * ends it with an ellipsis, which counts towards the length.
     */
    private function shorten(string $text, int $maxLength): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        $cut = mb_substr($text, 0, max(0, $maxLength - 1));
        // The cut ends a word when the next character is a space or punctuation.
        $endsWord = preg_match('/^[\s.,;:!?…)\]]/u', mb_substr($text, $maxLength - 1, 1)) === 1;
        $lastSpace = mb_strrpos($cut, ' ');
        if (! $endsWord && $lastSpace !== false) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        return mb_rtrim($cut, " \t.,;:") . '…';
    }

    private function collapse(string $text): string
    {
        return mb_trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $text) ?? $text);
    }
}
