<?php

declare(strict_types=1);
use Fabriciope\EnvChecker\Env\Parser as EnvParser;
use Fabriciope\EnvChecker\Exceptions\EnvFileNotFoundException;

it('should correctly parse the .env file and transform it into an array', function (): void {
    $envParsed = EnvParser::parseFile(__DIR__.'/../Fixtures/parser/.env');

    expect($envParsed['NAME'])->not->toBeEmpty()
        ->and($envParsed['NAME'])->not->toBeEmpty()
    ;
});

it('should retrieve a variable with a empty value', function (): void {
    $envParsed = EnvParser::parseFile(__DIR__.'/../Fixtures/parser/.env_with_empty_value');

    expect($envParsed)->toHaveKey('TEST')
        ->and($envParsed['TEST'])->toBeEmpty()
    ;

    expect($envParsed['API_KEY'])->not->toBeEmpty();
});

it(
    'should throw an exception if an attempt is made to parse a file that does not exist',
    function (): void {
        EnvParser::parseFile(__DIR__.'/../Fixtures/.wrong-env-file');
    },
)->throws(EnvFileNotFoundException::class);
