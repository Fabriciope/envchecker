<?php

declare(strict_types=1);
use Fabriciope\EnvChecker\Env\Comparator;

it('should identify that the arrays have no differences', function () {
    $comparator = new Comparator(
        base: ['NAME' => 'joao', 'AGE' => '20'],
        against: ['AGE' => '18', 'NAME' => 'example'],
    );

    expect($comparator->hasDifferences())->not->toBeTrue();
});

it('should identify that the arrays have differences', function () {
    $comparator = new Comparator(
        base: ['NAME' => 'pedro'],
        against: ['NAME' => 'example', 'AGE' => '18'],
    );

    expect($comparator->hasDifferences())->toBeTrue();
});

it('should identify whether the base .env file has any missing keys', function () {
    $comparator = new Comparator(
        base: ['NAME' => 'pedro'],
        against: ['NAME' => 'example', 'AGE' => '18', 'OCCUPATION' => 'example'],
    );

    expect($comparator->hasMissingKeys())->toBeTrue();
});

it('should identify which keys are missing in the base .env file', function () {
    $comparator = new Comparator(
        base: ['NAME' => 'pedro'],
        against: ['NAME' => 'example', 'AGE' => '18', 'OCCUPATION' => 'example'],
    );

    expect($comparator->missingKeys)->toEqual(['AGE', 'OCCUPATION']);
});

it('should identify the extra keys in the base .env file', function () {
    $comparator = new Comparator(
        base: ['NAME' => 'pedro', 'GENDER' => 'male'],
        against: ['NAME' => 'example'],
    );

    expect($comparator->extraKeys)->toEqual(['GENDER']);
});
