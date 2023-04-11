<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\ImportMap;

/**
 * @experimental
 *
 * @author Kévin Dunglas <kevin@dunglas.dev>
 */
final class PackageOptions
{
    public function __construct(
        public readonly bool $download = false,
        public readonly bool $preload = false,
        public readonly ?string $path = null,
    ) {
    }
}
