<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\DependencyInjection\Exception;

/**
 * Base RuntimeException for Dependency Injection component.
 *
 * @author Johannes M. Schmitt <schmittjoh@gmail.com>
 */
class RuntimeException extends \RuntimeException implements ExceptionInterface
{
    private $alwaysThrow = false;

    /**
     * This exception must always be thrown (useful for the AutowirePass).
     *
     * @param bool $alwaysThrow
     */
    public function setAlwaysThrow($alwaysThrow)
    {
        $this->alwaysThrow = $alwaysThrow;
    }

    /**
     * Should this exception always be thrown? (useful for the AutowirePass).
     *
     * @return bool
     */
    public function getAlwaysThrow()
    {
        return $this->alwaysThrow;
    }
}
