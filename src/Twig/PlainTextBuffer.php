<?php

declare(strict_types=1);

namespace Tomvondracek\LlmsTxt\Twig;

/**
 * Collects the text of an HTML fragment for {@see HtmlToTextExtension} in linear
 * time: deciding whether the text so far ends with punctuation only looks at its
 * last few characters, and whitespace is collapsed once, at the end.
 *
 * @internal
 */
final class PlainTextBuffer
{
    /**
     * Text that already ends a sentence or clause, optionally followed by closing quotes or brackets.
     * \p{Pi} too, because Czech closes quotes with “ (an "initial" quote in Unicode): „Ahoj.“
     */
    private const TERMINATED = '/[.!?…:;,][\p{Pi}\p{Pf}\p{Pe}"\']*$/u';

    /**
     * Characters of the collapsed end of the text kept for {@see self::TERMINATED}.
     */
    private const TAIL_LENGTH = 16;

    /** @var list<string> */
    private array $parts = [];

    /** The end of the text so far, whitespace collapsed and right-trimmed. */
    private string $tail = '';

    /** Whether whitespace follows the tail. */
    private bool $gap = false;

    /** Lower bound of the collapsed text's length. */
    private int $length = 0;

    /** Number of writes that added visible text. */
    private int $writes = 0;

    /** Punctuation owed to the block (or line break) that ended last, added once more text follows. */
    private ?string $pending = null;

    /**
     * @param int|null $limit stop collecting once the text is longer than this
     */
    public function __construct(
        private readonly ?int $limit = null,
    ) {
    }

    public static function collapse(string $text): string
    {
        return mb_trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $text) ?? $text);
    }

    /**
     * Appends text; once it is visible, it pays the punctuation still owed, unless it
     * starts with punctuation of its own (`<p>A</p>, more`).
     */
    public function write(string $text): void
    {
        $visible = self::collapse($text);
        if ($visible === '') {
            $this->space($text);

            return;
        }

        if ($this->pending !== null) {
            if (preg_match('/^[.,;:!?…)\]]/u', $visible) === 1) {
                $this->trimEnd();
            } else {
                $this->terminate($this->pending);
                $this->space();
            }
            $this->pending = null;
        }

        $this->parts[] = $text;
        $this->tail = mb_substr(self::collapse($this->tail . ($this->gap ? ' ' : '') . $text), -self::TAIL_LENGTH);
        $this->gap = preg_match('/^[\s\x{00A0}]$/u', mb_substr($text, -1)) === 1;
        $this->length += mb_strlen($visible);
        ++$this->writes;
    }

    public function space(string $whitespace = ' '): void
    {
        $this->parts[] = $whitespace;
        $this->gap = $this->gap || $whitespace !== '';
    }

    public function pending(): ?string
    {
        return $this->pending;
    }

    public function setPending(?string $punctuation): void
    {
        $this->pending = $punctuation;
    }

    public function writes(): int
    {
        return $this->writes;
    }

    public function isFull(): bool
    {
        return $this->limit !== null && $this->length > $this->limit;
    }

    public function toString(): string
    {
        return self::collapse(implode('', $this->parts));
    }

    /**
     * Ends the text with `$punctuation` unless it is empty or already ends with punctuation.
     */
    private function terminate(string $punctuation): void
    {
        $this->trimEnd();
        if ($this->tail === '' || preg_match(self::TERMINATED, $this->tail) === 1) {
            return;
        }

        $this->parts[] = $punctuation;
        $this->tail = mb_substr($this->tail . $punctuation, -self::TAIL_LENGTH);
        $this->gap = false;
        $this->length += mb_strlen($punctuation);
    }

    private function trimEnd(): void
    {
        $this->gap = false;
        while ($this->parts !== []) {
            $last = preg_replace('/[\s\x{00A0}]++$/u', '', array_pop($this->parts)) ?? '';
            if ($last !== '') {
                $this->parts[] = $last;

                return;
            }
        }
    }
}
