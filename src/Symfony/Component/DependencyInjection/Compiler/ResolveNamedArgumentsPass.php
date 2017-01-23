<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;

/**
 * Resolves named arguments to their corresponding numeric index.
 *
 * @author Kévin Dunglas <dunglas@gmail.com>
 */
class ResolveNamedArgumentsPass extends AbstractRecursivePass implements CompilerPassInterface
{
    private $constructors = array();

    /**
     * {@inheritdoc}
     *
     * @throws InvalidArgumentException
     */
    public function process(ContainerBuilder $container)
    {
        $throwingAutoloader = function ($class) { throw new \ReflectionException(sprintf('Class %s does not exist', $class)); };
        spl_autoload_register($throwingAutoloader);

        try {
            parent::process($container);
        } finally {
            spl_autoload_unregister($throwingAutoloader);

            // Free memory
            $this->constructors = array();
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function processValue($value, $isRoot = false)
    {
        if (!$value instanceof Definition) {
            return parent::processValue($value, $isRoot);
        }

        $class = $value->getClass();
        $originalArguments = $arguments = $value->getArguments();

        foreach ($arguments as $key => $argument) {
            if (!is_string($key)) {
                continue;
            }

            $constructor = $this->getConstructor($class, $key);
            foreach ($constructor->getParameters() as $index => $parameter) {
                if ($key === '$'.$parameter->getName()) {
                    unset($arguments[$key]);
                    $arguments[$index] = $argument;

                    continue 2;
                }
            }

            throw new InvalidArgumentException(sprintf('Unable to resolve the argument "%s" of the service "%s": the constructor of the class "%s" has no argument of this name.', $key, $this->currentId, $class));
        }

        if ($originalArguments !== $arguments) {
            ksort($arguments);
            $value->setArguments($arguments);
        }

        return parent::processValue($value, $isRoot);
    }

    /**
     * @param string|null $class
     * @param string      $key
     *
     * @throws InvalidArgumentException
     *
     * @return \ReflectionMethod
     */
    private function getConstructor($class, $key)
    {
        if (isset($this->constructors[$class])) {
            return $this->constructors[$class];
        }

        try {
            $reflectionClass = new \ReflectionClass($class);
        } catch (\ReflectionException $e) {
            if (null === $class) {
                throw new InvalidArgumentException(sprintf('Unable to resolve the argument "%s" of the service "%s": the class is not set.', $key, $this->currentId));
            }

            throw new InvalidArgumentException(sprintf('Unable to resolve the argument "%s" of the service "%s": the class "%s" does not exist.', $key, $this->currentId, $class));
        }

        if (!$constructor = $reflectionClass->getConstructor()) {
            throw new InvalidArgumentException(sprintf('Unable to resolve the argument "%s" of the service "%s": the class "%s" has no constructor.', $key, $this->currentId, $class));
        }

        if ($this->container->isTrackingResources()) {
            $this->container->addResource(AutowirePass::createResourceForClass($reflectionClass));
        }

        return $this->constructors[$class] = $constructor;
    }
}
