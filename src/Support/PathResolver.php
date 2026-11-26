<?php

declare(strict_types=1);

namespace Fabriciope\EnvChecker\Support;

use Composer\InstalledVersions;

readonly class PathResolver
{
    public function __construct(
        private string $cwd,
        private string $projectRoot,
    ) {}

    public static function fromEnvironment(): self
    {
        $cwd  = getcwd() ?: '.';
        $root = class_exists(InstalledVersions::class)
            ? realpath(InstalledVersions::getRootPackage()['install_path'])
            : false;

        return new self($cwd, $root ?: $cwd);
    }

    public function fromCwd(string $path): string
    {
        return $this->join($this->cwd, $path);
    }

    public function fromProjectRoot(string $path): string
    {
        return $this->join($this->projectRoot, $path);
    }

    private function join(string $base, string $path): string
    {
        $isAbsolute = str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || 1 === preg_match('~^[A-Za-z]:[\\\/]~', $path);   // C:\ ou C:/

        return $isAbsolute ? $path : rtrim($base, '/\\').\DIRECTORY_SEPARATOR.$path;
    }
}
