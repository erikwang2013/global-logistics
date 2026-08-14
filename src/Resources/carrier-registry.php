<?php

declare(strict_types=1);

use GlobalLogistics\Carriers\Domestic\Jt;
use GlobalLogistics\Carriers\Domestic\Sf;
use GlobalLogistics\Carriers\Domestic\Yto;
use GlobalLogistics\Carriers\Domestic\Zto;

// channel => code => adapter class
return [
    'domestic' => [
        'sf' => Sf::class,
        'zto' => Zto::class,
        'yto' => Yto::class,
        'jt' => Jt::class,
    ],
    'international' => [],
];
