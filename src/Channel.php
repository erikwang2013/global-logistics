<?php

declare(strict_types=1);

namespace GlobalLogistics;

enum Channel: string
{
    case Domestic = 'domestic';
    case International = 'international';
}
