<?php

declare(strict_types=1);

namespace Fabriciope\EnvChecker\Exceptions;

class InvalidCommandArgumentException extends \Exception
{
    public function __construct(
        protected readonly string $invalidArgument
    ) {
        parent::__construct(
            message: "Invalid argument passed to the command: {$this->invalidArgument}",
        );
    }
}
