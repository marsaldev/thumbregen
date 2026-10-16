<?php
/**
 * @author Marco Salvatore (marsaldev)
 * @license AFL-3.0
 */

namespace Marsaldev\Module\ThumbRegen\Service;

use Marsaldev\Module\ThumbRegen\Model\SelectionCriteria;

/**
 * Selects product IDs (by product, list of products, category, category subtree or whole catalog) and their images.
 *
 * Products are paginated by ID (keyset pagination), so the catalog is never loaded in memory
 * and the cost of each page does not grow with the offset. A list of products is queried in
 * chunks, so the SQL stays small whatever the size of the list.
 */
class ProductSelector
{
    const ID_CHUNK_SIZE = 1000;

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
        if (null === $criteria->getProductIds()) {
            return (int) $this->db->getValue('SELECT COUNT(DISTINCT p.`id_product`)' . $this->buildFromWhere($criteria));
        }

        $count = 0;
        foreach (array_chunk($criteria->getProductIds(), self::ID_CHUNK_SIZE) as $chunk) {
            $count += (int) $this->db->getValue('SELECT COUNT(DISTINCT p.`id_product`)' . $this->buildFromWhere($criteria, $chunk));
        }

        return $count;
    }

    /**
     * IDs of the list that do not exist, or are not associated with the shop in multistore.
     *
     * @param SelectionCriteria $criteria
     *
     * @return int[]
     */
    public function findMissingProductIds(SelectionCriteria $criteria)
    {
        if (null === $criteria->getProductIds()) {
            return [];
        }

        $missing = [];
        foreach (array_chunk($criteria->getProductIds(), self::ID_CHUNK_SIZE) as $chunk) {
            $rows = $this->db->executeS('SELECT DISTINCT p.`id_product`' . $this->buildFromWhere($criteria, $chunk));
            $found = array_map('intval', array_column($rows, 'id_product'));
            foreach (array_diff($chunk, $found) as $productId) {
                $missing[] = $productId;
            }
        }

        return $missing;
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
        if (null !== $criteria->getProductIds()) {
            return $this->fetchListedProductIds($criteria, $afterProductId, $limit);
        }

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
     * Next IDs of the list after $afterProductId that exist. Chunks with no existing product are
     * skipped, so an empty result always means the end of the list.
     *
     * @param SelectionCriteria $criteria
     * @param int $afterProductId
     * @param int $limit
     *
     * @return int[]
     */
    private function fetchListedProductIds(SelectionCriteria $criteria, $afterProductId, $limit)
    {
        $productIds = $criteria->getProductIds();
        $total = count($productIds);

        // Binary search of the first ID greater than $afterProductId (the list is sorted).
        $offset = 0;
        $high = $total;
        while ($offset < $high) {
            $middle = ($offset + $high) >> 1;
            if ($productIds[$middle] <= $afterProductId) {
                $offset = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        while ($offset < $total) {
            $chunk = array_slice($productIds, $offset, (int) $limit);
            $offset += count($chunk);
            $rows = $this->db->executeS(
                'SELECT DISTINCT p.`id_product`' . $this->buildFromWhere($criteria, $chunk)
                . ' ORDER BY p.`id_product` ASC'
            );
            if (!empty($rows)) {
                return array_map('intval', array_column($rows, 'id_product'));
            }
        }

        return [];
    }

    /**
     * @param SelectionCriteria $criteria
     * @param int[]|null $productIds chunk of the criteria's product list, required when the criteria has one
     *
     * @return string
     */
    private function buildFromWhere(SelectionCriteria $criteria, $productIds = null)
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

        if (null !== $criteria->getProductIds()) {
            if (empty($productIds)) {
                // Never fall back to the whole catalog when a list is expected.
                throw new \LogicException('A product list selection must be queried by chunk.');
            }
            $where .= ' AND p.`id_product` IN (' . implode(',', array_map('intval', $productIds)) . ')';
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
