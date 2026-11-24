<?php

declare(strict_types=1);

namespace Fabriciope\EnvChecker\Exceptions;

class EnvFileNotFoundException extends \Exception
{
    public function __construct(
        protected readonly string $path
    ) {
        parent::__construct(
            message: "Env file could not be found: {$this->path}",
        );
    }
}
