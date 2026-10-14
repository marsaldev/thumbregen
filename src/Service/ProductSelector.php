<?php
/**
 * @author Marco Salvatore (marsaldev)
 * @license AFL-3.0
 */

namespace Marsaldev\Module\ThumbRegen\Service;

use Marsaldev\Module\ThumbRegen\Model\SelectionCriteria;

/**
 * Selects product IDs (by product, category, category subtree or whole catalog) and their images.
 *
 * Products are paginated by ID (keyset pagination), so the catalog is never loaded in memory
 * and the cost of each page does not grow with the offset.
 */
class ProductSelector
{
    /** @var DatabaseClient */
    private $db;

    public function __construct(DatabaseClient $db)
    {
        $this->db = $db;
    }

    /**
     * @param int $productId
     *
     * @return bool
     */
    public function productExists($productId)
    {
        return (bool) $this->db->getValue(
            'SELECT `id_product` FROM `' . _DB_PREFIX_ . 'product` WHERE `id_product` = ' . (int) $productId
        );
    }

    /**
     * @param int $productId
     * @param int $shopId
     *
     * @return bool
     */
    public function productBelongsToShop($productId, $shopId)
    {
        return (bool) $this->db->getValue(
            'SELECT `id_product` FROM `' . _DB_PREFIX_ . 'product_shop`
            WHERE `id_product` = ' . (int) $productId . ' AND `id_shop` = ' . (int) $shopId
        );
    }

    /**
     * @param int $categoryId
     *
     * @return array|null ['id_category' => int, 'nleft' => int, 'nright' => int]
     */
    public function getCategory($categoryId)
    {
        $row = $this->db->getRow(
            'SELECT `id_category`, `nleft`, `nright` FROM `' . _DB_PREFIX_ . 'category` WHERE `id_category` = ' . (int) $categoryId
        );

        if (null === $row) {
            return null;
        }

        return [
            'id_category' => (int) $row['id_category'],
            'nleft' => (int) $row['nleft'],
            'nright' => (int) $row['nright'],
        ];
    }

    /**
     * @param SelectionCriteria $criteria
     *
     * @return int
     */
    public function countProducts(SelectionCriteria $criteria)
    {
        return (int) $this->db->getValue('SELECT COUNT(DISTINCT p.`id_product`)' . $this->buildFromWhere($criteria));
    }

    /**
     * @param SelectionCriteria $criteria
     * @param int $afterProductId last ID of the previous batch (0 for the first one)
     * @param int $limit
     *
     * @return int[]
     */
    public function fetchProductIds(SelectionCriteria $criteria, $afterProductId, $limit)
    {
        $rows = $this->db->executeS(
            'SELECT DISTINCT p.`id_product`' . $this->buildFromWhere($criteria)
            . ' AND p.`id_product` > ' . (int) $afterProductId
            . ' ORDER BY p.`id_product` ASC LIMIT ' . (int) $limit
        );

        return array_map('intval', array_column($rows, 'id_product'));
    }

    /**
     * @param int[] $productIds
     * @param SelectionCriteria $criteria
     *
     * @return array<int, int[]> image IDs grouped by product ID, in position order
     */
    public function fetchImageIdsByProduct(array $productIds, SelectionCriteria $criteria)
    {
        if (empty($productIds)) {
            return [];
        }

        $sql = 'SELECT i.`id_image`, i.`id_product` FROM `' . _DB_PREFIX_ . 'image` i';
        if ($criteria->isMultistore()) {
            $sql .= ' INNER JOIN `' . _DB_PREFIX_ . 'image_shop` ims
                ON (ims.`id_image` = i.`id_image` AND ims.`id_shop` = ' . $criteria->getShopId() . ')';
        }
        $sql .= ' WHERE i.`id_product` IN (' . implode(',', array_map('intval', $productIds)) . ')
            ORDER BY i.`id_product` ASC, i.`position` ASC, i.`id_image` ASC';

        $images = [];
        foreach ($this->db->executeS($sql) as $row) {
            $images[(int) $row['id_product']][] = (int) $row['id_image'];
        }

        return $images;
    }

    /**
     * @param SelectionCriteria $criteria
     *
     * @return string
     */
    private function buildFromWhere(SelectionCriteria $criteria)
    {
        $sql = ' FROM `' . _DB_PREFIX_ . 'product` p';
        $where = ' WHERE 1';

        if ($criteria->isMultistore()) {
            $sql .= ' INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps
                ON (ps.`id_product` = p.`id_product` AND ps.`id_shop` = ' . $criteria->getShopId() . ')';
        }

        if (null !== $criteria->getProductId()) {
            $where .= ' AND p.`id_product` = ' . $criteria->getProductId();
        }

        if (null !== $criteria->getCategoryId()) {
            // category_product, not id_category_default: products merely associated with the category count too.
            $sql .= ' INNER JOIN `' . _DB_PREFIX_ . 'category_product` cp ON (cp.`id_product` = p.`id_product`)';
            if ($criteria->includesSubcategories()) {
                $sql .= ' INNER JOIN `' . _DB_PREFIX_ . 'category` c ON (c.`id_category` = cp.`id_category`)';
                $where .= ' AND c.`nleft` >= ' . $criteria->getCategoryLeft() . ' AND c.`nright` <= ' . $criteria->getCategoryRight();
            } else {
                $where .= ' AND cp.`id_category` = ' . $criteria->getCategoryId();
            }
        }

        return $sql . $where;
    }
}
