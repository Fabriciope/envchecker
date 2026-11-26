<?php

declare(strict_types=1);

namespace Fabriciope\EnvChecker\Console;

use Fabriciope\EnvChecker\Env\Comparator as EnvComparator;
use Fabriciope\EnvChecker\Env\Parser as EnvParser;
use Fabriciope\EnvChecker\Exceptions\EnvFileNotFoundException;
use Fabriciope\EnvChecker\Exceptions\InvalidCommandArgumentException;
use Fabriciope\EnvChecker\Support\PathResolver;

/** @internal Fora do contrato público: chamada apenas por bin/envchecker. */
final class Kernel
{
    public const int SUCCESS = 0;

    public const int SOFTWARE_ERROR = 70;

    public const int USAGE_ERROR = 64;

    public const int NOINPUT_ERROR = 66;

    public const int DIFFERENCES_ERROR = 1;

    public const array ACCEPTED_FORMATS = ['text', 'json'];

    public function __construct(
        private readonly Input $input,
        private readonly Output $output,
    ) {}

    public function run(): int
    {
        try {
            $this->input->parse();

            $pathResolver  = PathResolver::fromEnvironment();
            $envComparator = new EnvComparator(
                EnvParser::parseFile($this->resolvePath($pathResolver, 'base', '.env')),
                EnvParser::parseFile($this->resolvePath($pathResolver, 'against', '.env.example')),
            );

            $this->printReport($envComparator);

            return $envComparator->hasDifferences() ? self::DIFFERENCES_ERROR : self::SUCCESS;
        } catch (InvalidCommandArgumentException $e) {
            $this->output->error($e->getMessage());

            return self::USAGE_ERROR;
        } catch (EnvFileNotFoundException $e) {
            $this->output->error($e->getMessage());

            return self::NOINPUT_ERROR;
        } catch (\Throwable $e) {
            $this->output->error("Unexpected internal error: {$e->getMessage()}");

            return self::SOFTWARE_ERROR;
        }
    }

    private function resolvePath(PathResolver $pathResolver, string $flag, string $default): string
    {
        return $this->input->hasLongOption($flag)
                ? $pathResolver->fromCwd($this->input->getLongOption($flag))
                : $pathResolver->fromProjectRoot($default);
    }

    private function printReport(EnvComparator $comparator): void
    {
        if ('json' === $this->input->getLongOption('format', 'text')) {
            $this->printReportInJson($comparator);

            return;
        }

        $this->printReportInText($comparator);
    }

    private function printReportInText(EnvComparator $comparator): void
    {
        $this->section('missing keys', $comparator->missingKeys, 31);
        $this->section('empty keys', $comparator->emptyKeys, 33);
        $this->section('extra keys', $comparator->extraKeys, 34);
        if (!$comparator->hasDifferences()) {
            $this->output->line($this->output->style('.env is in sync with the example file', 32));
        }
    }

    private function printReportInJson(EnvComparator $comparator): void
    {
        $this->output->line(json_encode([
            'has_differences' => $comparator->hasDifferences(),
            'missing_keys'    => $comparator->missingKeys,
            'empty_keys'      => $comparator->emptyKeys,
            'extra_keys'      => $comparator->extraKeys,
        ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    private function section(string $label, array $keys, int $color): void
    {
        if ([] !== $keys) {
            $this->output->line(
                $this->output->style(\sprintf('%d %s: ', \count($keys), $label), $color).implode(', ', $keys)
            );
        }
    }
}
