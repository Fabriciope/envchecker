<?php

declare(strict_types=1);

use Fabriciope\EnvChecker\Console\Input;
use Fabriciope\EnvChecker\Console\Kernel;
use Fabriciope\EnvChecker\Console\Output;

function runEnvChekcer(string ...$args): array
{
    $out = fopen('php://memory', 'w+');
    $err = fopen('php://memory', 'w+');
    $kernel = new Kernel(Input::make(['envchecker', ...$args]), new Output($out, $err));
    $exitCode = $kernel->run();

    rewind($out);
    rewind($err);

    return [$exitCode, stream_get_contents($out), stream_get_contents($err)];
}

test('the exit code should be 0 when the files match', function () {
    $baseEnvFile = __DIR__.'/../Fixtures/ok/.env';
    $againstEnvFile = __DIR__.'/../Fixtures/ok/.env.example';
    [$code, $stdout, $sdterr] = runEnvChekcer("--base={$baseEnvFile}", "--against={$againstEnvFile}");

    expect($code)->toEqual(Kernel::SUCCESS);
});

test('the exit code should be 1 when the files do not match', function () {
    $baseEnvFile = __DIR__.'/../Fixtures/with_differences/.env';
    $againstEnvFile = __DIR__.'/../Fixtures/with_differences/.env.example';
    [$code] = runEnvChekcer("--base={$baseEnvFile}", "--against={$againstEnvFile}");

    expect($code)->toEqual(Kernel::DIFFERENCES_ERROR);
});

it('should identify when an invalid flag is passed to the command', function () {
    [$code, $stdout, $stderr] = runEnvChekcer('-x');

    expect($code)->toEqual(Kernel::USAGE_ERROR)
        ->and($stdout)->toBe('')
        ->and($stderr)->toContain('-x')
    ;
});

it('should identify when an invalid env file is passed to the command via the flags', function () {
    $envName = '.wrong-env';
    $baseEnvFile = __DIR__."/../Fixtures/with_differences/{$envName}";
    [$code, $stdout, $stderr] = runEnvChekcer("--base={$baseEnvFile}");

    expect($code)->toEqual(Kernel::NOINPUT_ERROR)
        ->and($stdout)->toBe('')
        ->and($stderr)->toContain($envName)
    ;
});

it('should return an error when an incorrect value is passed to the format flag', function () {
    $baseEnvFile = __DIR__.'/../Fixtures/ok/.env';
    $againstEnvFile = __DIR__.'/../Fixtures/ok/.env.example';
    [$code, , $sdterr] = runEnvChekcer("--base={$baseEnvFile}", "--against={$againstEnvFile}", '--format=wrong-format');

    expect($code)->toEqual(Kernel::USAGE_ERROR)
        ->and($sdterr)->toContain('Invalid argument passed to the command: --format')
    ;
});
