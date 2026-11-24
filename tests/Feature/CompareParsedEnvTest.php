<?php

declare(strict_types=1);

use Fabriciope\EnvChecker\Env\Comparator;
use Fabriciope\EnvChecker\Env\Parser;

it('should identify whether the files contain differences', function () {
    $comparator = new Comparator(
        Parser::parseFile(__DIR__.'/../Fixtures/with_differences/.env'),
        Parser::parseFile(__DIR__.'/../Fixtures/with_differences/.env.example')
    );

    expect($comparator->hasDifferences())->toBeTrue();
});

it('should identify the missing keys in the base .env file', function () {
    $comparator = new Comparator(
        Parser::parseFile(__DIR__.'/../Fixtures/with_differences/.env'),
        Parser::parseFile(__DIR__.'/../Fixtures/with_differences/.env.example')
    );

    expect($comparator->hasMissingKeys())->toBeTrue()
        ->and($comparator->missingKeys)->toEqual(['JOB'])
    ;
});

it('should identify the empty keys in the base.env file', function () {
    $comparator = new Comparator(
        Parser::parseFile(__DIR__.'/../Fixtures/with_differences/.env'),
        Parser::parseFile(__DIR__.'/../Fixtures/with_differences/.env.example')
    );

    expect($comparator->emptyKeys)->toEqual(['AGE']);
});

it('should identify the extra keys in the base .env file', function () {
    $comparator = new Comparator(
        Parser::parseFile(__DIR__.'/../Fixtures/with_differences/.env'),
        Parser::parseFile(__DIR__.'/../Fixtures/with_differences/.env.example')
    );

    expect($comparator->extraKeys)->toEqual(['GENDER', 'HEIGHT']);
});
