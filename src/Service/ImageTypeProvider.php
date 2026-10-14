<?php
/**
 * @author Marco Salvatore (marsaldev)
 * @license AFL-3.0
 */

namespace Marsaldev\Module\ThumbRegen\Service;

use ImageType;

/**
 * Image types enabled for products (image_type.products = 1).
 */
class ImageTypeProvider
{
    /**
     * @return array[] rows with id_image_type, name, width, height
     */
    public function getProductImageTypes()
    {
        $types = ImageType::getImagesTypes('products');

        return is_array($types) ? array_values($types) : [];
    }

    /**
     * @return string[]
     */
    public function getProductImageTypeNames()
    {
        return array_column($this->getProductImageTypes(), 'name');
    }

    /**
     * @param string[] $names requested type names, empty for all product types
     *
     * @return array[] the matching image type rows
     *
     * @throws \InvalidArgumentException when a name is unknown or not enabled for products
     */
    public function resolve(array $names)
    {
        $available = $this->getProductImageTypes();
        if (empty($names)) {
            return $available;
        }

        $byName = [];
        foreach ($available as $type) {
            $byName[$type['name']] = $type;
        }

        $resolved = [];
        $unknown = [];
        foreach (array_unique($names) as $name) {
            if (isset($byName[$name])) {
                $resolved[] = $byName[$name];
            } else {
                $unknown[] = $name;
            }
        }

        if (!empty($unknown)) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown image type(s) or not enabled for products: %s. Valid product image types: %s',
                implode(', ', $unknown),
                implode(', ', array_keys($byName))
            ));
        }

        return $resolved;
    }
}
