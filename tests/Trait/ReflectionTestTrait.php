<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Tests\Trait;

use ReflectionClass;

trait ReflectionTestTrait
{
    public function setPropertyValue(object $object, string $propertyName, mixed $propertyValue): void
    {
        $reflectionClass = new ReflectionClass($object);
        $reflectionProperty = $reflectionClass->getProperty($propertyName);
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($object, $propertyValue);
    }
    protected function getPropertyValue(object $object, string $propertyName): mixed
    {
        $reflectionClass = new ReflectionClass($object);
        $reflectionProperty = $reflectionClass->getProperty($propertyName);
        $reflectionProperty->setAccessible(true);
        return $reflectionProperty->getValue($object);
    }
}
