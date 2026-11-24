<?php

declare(strict_types=1);

arch('o domínio não conhece o terminal')
    ->expect('Fabriciope\EnvChecker\Env')
    ->not->toUse('Fabriciope\EnvChecker\Console')
;

arch('nada de funções de debug no código distribuído')
    ->expect(['dd', 'dump', 'var_dump'])
    ->not->toBeUsed();
