<?php
/**
 * @author Marco Salvatore (marsaldev)
 * @license AFL-3.0
 */

namespace Marsaldev\Module\ThumbRegen\Model;

/**
 * Which products a run targets: a single product, a category (optionally with its subtree) or the whole catalog.
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

    /**
     * @param int $shopId
     * @param bool $multistore when true, products and images are filtered through product_shop / image_shop
     * @param int|null $productId
     * @param int|null $categoryId
     * @param int|null $categoryLeft nested set bounds, only set when subcategories are included
     * @param int|null $categoryRight
     */
    public function __construct($shopId, $multistore, $productId = null, $categoryId = null, $categoryLeft = null, $categoryRight = null)
    {
        $this->shopId = (int) $shopId;
        $this->multistore = (bool) $multistore;
        $this->productId = null !== $productId ? (int) $productId : null;
        $this->categoryId = null !== $categoryId ? (int) $categoryId : null;
        $this->categoryLeft = null !== $categoryLeft ? (int) $categoryLeft : null;
        $this->categoryRight = null !== $categoryRight ? (int) $categoryRight : null;
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
}
