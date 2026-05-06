<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Cache;

final class InvalidArgumentException extends \InvalidArgumentException implements \Psr\Cache\InvalidArgumentException
{
}
