<?php

declare(strict_types=1);

namespace Fabriciope\EnvChecker\Console;

/** @internal */
final class Output
{
    /**
     * @param resource $out
     * @param resource $err
     */
    public function __construct(
        private $out,
        private $err,
        private bool $colors = false,
    ) {}

    public static function fromStd(): self
    {
        return new self(STDOUT, STDERR, colors: self::supportsColor(STDOUT));
    }

    public function write(string $text): void
    {
        fwrite($this->out, $text);
    }

    /**
     * @param int $sgr Select Graphic Rendition
     */
    public function style(string $text, int $sgr): string
    {
        $sgr = (string) $sgr;

        return $this->colors ? "\033[{$sgr}m{$text}\033[0m" : $text;
    }

    public function line(string $text): void
    {
        $this->write($text.PHP_EOL);
    }

    public function error(string $message): void
    {
        fwrite($this->err, $this->style('envchecker: ', 31).$message.PHP_EOL);
    }

    /** @param resource $stream */
    private static function supportsColor($stream): bool
    {
        return '' === (string) getenv('NO_COLOR') && stream_isatty($stream);
    }
}
