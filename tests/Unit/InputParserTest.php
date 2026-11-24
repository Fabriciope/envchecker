<?php

declare(strict_types=1);

use Fabriciope\EnvChecker\Console\Input;
use Fabriciope\EnvChecker\Exceptions\InvalidCommandArgumentException;

describe('parsing arguments', function () {
    it('should throw an exception when an argument is passed that is not in the list of accepted options', function () {
        // command -> ./bin/envchecker --wrong-flag
        $argvFixture = ['./bin/envchecker', '--wrong-flag'];
        $input = new Input($argvFixture);
        $input->parse();
    })->throws(InvalidCommandArgumentException::class);

    it('should recognize long flags within the list of accepted options', function () {
        // command -> ./bin/envchecker --base --against --format
        $argvFixture = ['./bin/envchecker', '--base=.env', '--against=.env.example', '--format=json'];
        $input = new Input($argvFixture);
        $input->parse();

        expect($input->hasLongOption('base'))->toBeTrue()
            ->and($input->hasLongOption('against'))->toBeTrue()
            ->and($input->hasLongOption('format'))->toBeTrue()
        ;
    });

    it("should recognize long flags with values after '='", function () {
        // command -> ./bin/envchecker --base=.env.test
        $flagName = 'base';
        $valueFakeFlag = '.env.test';
        $argvFixture = ['./bin/envchecker', "--{$flagName}={$valueFakeFlag}"];
        $input = new Input($argvFixture);
        $input->parse();

        expect($input->hasLongOption($flagName))->toBeTrue();
        expect($input->getLongOption($flagName))->toEqual($valueFakeFlag);
    });

    it('should throw an exception when an argument does not start with --', function () {
        // command -> ./bin/envchecker -base
        $argvFixture = ['./bin/envchecker', '-base'];
        $input = new Input($argvFixture);
        $input->parse();
    })->throws(InvalidCommandArgumentException::class);
});

describe('reading options', function () {
    it('should return a default value when retrieving a flag that has not been defined', function () {
        // command -> ./bin/envchecker
        $argvFixture = ['./bin/envchecker'];
        $input = new Input($argvFixture);
        $input->parse();

        $defaultValue = 'default_value';
        $optionValue = $input->getLongOption('invalidArgument', $defaultValue);

        expect($optionValue)->toEqual($defaultValue);
    });

    it('should throw an exception when retrieving a flag that has been defined but has no value', function () {
        // command -> ./bin/envchecker --base
        $argvFixture = ['./bin/envchecker', '--base'];
        $input = new Input($argvFixture);
        $input->parse();

        $input->getLongOption('base');
    })->throws(InvalidCommandArgumentException::class);
});
