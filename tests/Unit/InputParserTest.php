<?php

declare(strict_types=1);

use Fabriciope\EnvChecker\Console\Input;
use Fabriciope\EnvChecker\Exceptions\InvalidCommandArgumentException;

function makeInput(string ...$args): Input
{
    $input = new Input(['./bin/envchecker', ...$args]);
    $input->parse();

    return $input;
}

describe('parsing arguments', function (): void {
    it('should throw an exception when an argument is passed that is not in the list of accepted options', function (): void {
        makeInput('--wrong-flag'); // command -> ./bin/envchecker --wrong-flag
    })->throws(InvalidCommandArgumentException::class);

    it('should recognize long flags within the list of accepted options', function (): void {
        $input = makeInput('--base=.env', '--against=.env.example', '--format=json'); // command -> ./bin/envchecker --base --against --format

        expect($input->hasLongOption('base'))->toBeTrue()
            ->and($input->hasLongOption('against'))->toBeTrue()
            ->and($input->hasLongOption('format'))->toBeTrue()
        ;
    });

    it("should recognize long flags with values after '='", function (): void {
        $flagName      = 'base';
        $valueFakeFlag = '.env.test';
        $input         = makeInput("--{$flagName}={$valueFakeFlag}"); // command -> ./bin/envchecker --base=.env.test

        expect($input->hasLongOption($flagName))->toBeTrue();
        expect($input->getLongOption($flagName))->toEqual($valueFakeFlag);
    });

    it('should throw an exception when an argument does not start with --', function (): void {
        makeInput('-base'); // command -> ./bin/envchecker -base
    })->throws(InvalidCommandArgumentException::class);
});

describe('reading options', function (): void {
    it('should return a default value when retrieving a flag that has not been defined', function (): void {
        $input = makeInput(); // command -> ./bin/envchecker

        $defaultValue = 'default_value';
        $optionValue  = $input->getLongOption('invalidArgument', $defaultValue);

        expect($optionValue)->toEqual($defaultValue);
    });

    it('should throw an exception when retrieving a flag that has been defined but has no value', function (): void {
        $input = makeInput('--base'); // command -> ./bin/envchecker --base

        $input->getLongOption('base');
    })->throws(InvalidCommandArgumentException::class);
});
