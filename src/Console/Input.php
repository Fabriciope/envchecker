<?php

declare(strict_types=1);

namespace Fabriciope\EnvChecker\Console;

use Fabriciope\EnvChecker\Exceptions\InvalidCommandArgumentException;

/** @internal */
final class Input
{
    public const array ACCEPTED_OPTIONS = ['base', 'against', 'format'];

    /**
     * Command flags.
     *
     * If the value is null, it means the options has no value.
     *
     * @var array<string, null|bool|int|string> options
     */
    private array $longOptions = [];

    /**
     * @param array $argv $argv vindo da variável global do script
     *
     * @throws InvalidCommandArgumentException
     */
    public function __construct(
        public readonly array $argv,
    ) {}

    public static function make(array $argv): self
    {
        return new self($argv);
    }

    /**
     * Recebe $argv e analisa os argumentos passados
     * ao comando para popular o atributo $this->longOptions.
     *
     * @throws \Fabriciope\Envchecker\Exceptions\InvalidCommandArgumentException
     */
    public function parse(): void
    {
        $arguments = array_slice($this->argv, 1);

        foreach ($arguments as $argument) {
            $argument = trim($argument);
            if (!str_starts_with($argument, '--')) {
                throw new InvalidCommandArgumentException($argument);
            }

            /**
             * Nome do argumento passado pelo comando.
             *
             * Nome após o '--' e antes de '=' caso tenha.
             *
             * @var string $argumentName
             */
            $argumentName = substr($argument, 2);

            /**
             * Valor atribuído ao argumento.
             *
             * Se não haver '=' então o padrão será null,
             * que significa que o argumento é somente
             *  uma flag solta no comando.
             *
             * @var null|string $value
             */
            $value = null;

            if (str_contains($argumentName, '=')) {
                [$argumentName, $value] = explode('=', $argumentName, 2);
            }

            if (!$this->validateArgument($argumentName, $value)) {
                throw new InvalidCommandArgumentException("--{$argumentName}");
            }

            $this->longOptions[$argumentName] = $value;
        }
    }

    public function hasLongOption(string $longOptionName): bool
    {
        return array_key_exists($longOptionName, $this->longOptions);
    }

    public function getLongOption(string $longOptionName, string $default = ''): bool|int|string
    {
        if (!$this->hasLongOption($longOptionName)) {
            return $default;
        }

        if (is_null($this->longOptions[$longOptionName])) {
            throw new InvalidCommandArgumentException($longOptionName);
        }

        return $this->longOptions[$longOptionName];
    }

    public function allOptions(): array
    {
        return $this->longOptions;
    }

    private function validateArgument(string $argumentName, mixed $value): bool
    {
        return '' != $value
            && in_array($argumentName, self::ACCEPTED_OPTIONS, true)
            && ('format' !== $argumentName || in_array($value, Kernel::ACCEPTED_FORMATS, true));
    }
}
