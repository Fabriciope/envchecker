<?php

declare(strict_types=1);

namespace Fabriciope\EnvChecker\Env;

// TODO(proximos): pensar em usar um dto para trafegar os dados do parser para comparator
// ao inves de ser somente um array sem significado de estrutura propria
final class Comparator
{
    /** @var list<string> */
    public readonly array $missingKeys;

    /** @var list<string> */
    public readonly array $emptyKeys;

    /** @var list<string> */
    public readonly array $extraKeys;

    public function __construct(
        private array $base,
        private array $against
    ) {
        $this->missingKeys = array_keys(array_diff_key($against, $base));
        $this->emptyKeys = array_keys(array_filter(
            array_intersect_key($base, $against),
            fn (string $value): bool => '' === $value,
        ));
        $this->extraKeys = array_keys(array_diff_key($base, $against));
    }

    /**
     * If the base .env file contains a variable whith an
     * empty value, this will not be considered a difference
     * in this function.
     */
    public function hasDifferences(): bool
    {
        return [] !== $this->missingKeys || [] !== $this->extraKeys;
    }

    public function hasMissingKeys(): bool
    {
        return count($this->missingKeys) > 0;
    }
}
