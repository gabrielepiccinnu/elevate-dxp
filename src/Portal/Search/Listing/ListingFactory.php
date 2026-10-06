<?php

declare(strict_types=1);

namespace ElevateDxp\Portal\Search\Listing;

use OpenDxp\Model\Asset;
use OpenDxp\Model\DataObject\ClassDefinition;
use OpenDxp\Model\DataObject\Listing\Concrete as ConcreteListing;

/**
 * Creates OpenDXP listings. Isolated so the search backend can be unit-tested with stubs.
 */
class ListingFactory
{
    public function assets(): Asset\Listing
    {
        return new Asset\Listing();
    }

    public function objects(string $className): ConcreteListing
    {
        $class = $this->classDefinition($className);
        $listingClass = 'OpenDxp\\Model\\DataObject\\'.ucfirst($class->getName()).'\\Listing';
        if (!class_exists($listingClass)) {
            throw new \InvalidArgumentException(\sprintf('No listing class for "%s".', $className));
        }
        $listing = new $listingClass();
        if (!$listing instanceof ConcreteListing) {
            throw new \InvalidArgumentException(\sprintf('Unexpected listing class for "%s".', $className));
        }

        return $listing;
    }

    /** True when the class defines the field (including localized fields). */
    public function hasObjectField(string $className, string $field): bool
    {
        $class = $this->classDefinition($className);
        if ($class->getFieldDefinition($field) !== null) {
            return true;
        }
        $localized = $class->getFieldDefinition('localizedfields');

        return $localized !== null && method_exists($localized, 'getFieldDefinition') && $localized->getFieldDefinition($field) !== null;
    }

    private function classDefinition(string $className): ClassDefinition
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $className)) {
            throw new \InvalidArgumentException(\sprintf('Invalid class name "%s".', $className));
        }

        return ClassDefinition::getByName($className)
            ?? throw new \InvalidArgumentException(\sprintf('Unknown data object class "%s".', $className));
    }
}
