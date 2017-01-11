<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\DependencyInjection\Tests\Fixtures;

/**
 * To test getter autowiring with PHP >= 7.1.
 *
 * @author Kévin Dunglas <dunglas@gmail.com>
 */
class GetterOverriding
{
    public function getFoo(): ?Foo
    {
        // should be called
    }

    protected function getBar(): Bar
    {
        // should be called
    }

    public function getNoTypeHint()
    {
        // should not be called
    }

    public function getUnknown(): NotExist
    {
        // should not be called
    }

    public function getExplicitlyDefined(): B
    {
        // should be called but not autowired
    }

    public function getScalar(): string
    {
        // should not be called
    }

    final public function getFinal(): A
    {
        // should not be called
    }

    public function &getReference(): A
    {
        // should not be called
    }
}
