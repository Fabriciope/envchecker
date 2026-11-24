<?php

declare(strict_types=1);

namespace Fabriciope\EnvChecker\Env;

use Fabriciope\EnvChecker\Exceptions\EnvFileNotFoundException;

final class Parser
{
    /**
     * @param string $content Should be the content of the .env* file
     *
     * @return array<string, string> Key is the .env* variable name and the value is the content
     */
    public static function parse(string $content): array
    {
        $vars = [];
        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            $line = trim($line);
            if ('' === $line || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = substr($line, 7);
            }

            [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
            $vars[trim($key)] = trim(trim($value), '"\'');
        }

        return $vars;
    }

    /**
     * @param string $path File to be parsed
     *
     * @return array The content of the file already mapped
     */
    public static function parseFile(string $path): array
    {
        if (!is_file($path) || !file_exists($path) || !is_readable($path)) {
            throw new EnvFileNotFoundException($path);
        }

        $content = file_get_contents($path);
        if (false === $content) {
            throw new EnvFileNotFoundException($path);
        }

        return self::parse($content);
    }
}
