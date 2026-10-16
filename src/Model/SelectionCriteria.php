<?php
/**
 * @author Marco Salvatore (marsaldev)
 * @license AFL-3.0
 */

namespace Marsaldev\Module\ThumbRegen\Model;

/**
 * Which products a run targets: a single product, a list of products, a category (optionally with its subtree)
 * or the whole catalog.
 */
class SelectionCriteria
{
    /** @var int */
    private $shopId;

    /** @var bool */
    private $multistore;

    /** @var int|null */
    private $productId;

    /** @var int|null */
    private $categoryId;

    /** @var int|null */
    private $categoryLeft;

    /** @var int|null */
    private $categoryRight;

    /** @var int[]|null */
    private $productIds;

    /**
     * @param int $shopId
     * @param bool $multistore when true, products and images are filtered through product_shop / image_shop
     * @param int|null $productId
     * @param int|null $categoryId
     * @param int|null $categoryLeft nested set bounds, only set when subcategories are included
     * @param int|null $categoryRight
     * @param int[]|null $productIds explicit list of products (e.g. from --product-file)
     */
    public function __construct($shopId, $multistore, $productId = null, $categoryId = null, $categoryLeft = null, $categoryRight = null, $productIds = null)
    {
        $this->shopId = (int) $shopId;
        $this->multistore = (bool) $multistore;
        $this->productId = null !== $productId ? (int) $productId : null;
        $this->categoryId = null !== $categoryId ? (int) $categoryId : null;
        $this->categoryLeft = null !== $categoryLeft ? (int) $categoryLeft : null;
        $this->categoryRight = null !== $categoryRight ? (int) $categoryRight : null;
        if (null !== $productIds) {
            // Sorted and unique: ProductSelector pages the list by ID, like the other selections.
            $productIds = array_values(array_unique(array_map('intval', (array) $productIds)));
            sort($productIds);
        }
        $this->productIds = $productIds;
    }

    /**
     * @return int
     */
    public function getShopId()
    {
        return $this->shopId;
    }

    /**
     * @return bool
     */
    public function isMultistore()
    {
        return $this->multistore;
    }

    /**
     * @return int|null
     */
    public function getProductId()
    {
        return $this->productId;
    }

    /**
     * @return int|null
     */
    public function getCategoryId()
    {
        return $this->categoryId;
    }

    /**
     * @return bool
     */
    public function includesSubcategories()
    {
        return null !== $this->categoryLeft && null !== $this->categoryRight;
    }

    /**
     * @return int|null
     */
    public function getCategoryLeft()
    {
        return $this->categoryLeft;
    }

    /**
     * @return int|null
     */
    public function getCategoryRight()
    {
        return $this->categoryRight;
    }

    /**
     * @return int[]|null unique IDs in ascending order, null when the selection is not a list
     */
    public function getProductIds()
    {
        return $this->productIds;
    }
}
