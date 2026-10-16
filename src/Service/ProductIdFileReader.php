<?php
/**
 * @author Marco Salvatore (marsaldev)
 * @license AFL-3.0
 */

namespace Marsaldev\Module\ThumbRegen\Service;

/**
 * Reads product IDs from a CSV or plain text file: one product per row, the ID in the first column.
 *
 * Columns may be separated by comma, semicolon, tab or pipe, so exports from the back office or a
 * spreadsheet work as they are. Empty rows are ignored, and so is a first row whose first column
 * is not an ID (header). Any other invalid value is an error, to avoid silently processing half a file.
 */
class ProductIdFileReader
{
    /**
     * @param string $path
     *
     * @return int[] unique IDs, in ascending order
     *
     * @throws \InvalidArgumentException
     */
    public function read($path)
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException(sprintf('--product-file: "%s" does not exist or is not readable.', $path));
        }

        $handle = @fopen($path, 'rb');
        if (false === $handle) {
            throw new \InvalidArgumentException(sprintf('--product-file: cannot open "%s".', $path));
        }

        $ids = [];
        $lineNumber = 0;
        $firstRow = true;
        try {
            while (false !== ($line = fgets($handle))) {
                ++$lineNumber;
                if (1 === $lineNumber) {
                    // UTF-8 BOM, as written by Excel
                    $line = preg_replace('/^\xEF\xBB\xBF/', '', $line);
                }

                $parts = preg_split('/[,;\t|]/', $line, 2);
                $value = trim($parts[0], " \t\r\n\"'");
                if ('' === $value && '' === trim($line)) {
                    continue;
                }

                if (ctype_digit($value) && (int) $value > 0) {
                    $ids[(int) $value] = true;
                } elseif (!$firstRow) {
                    throw new \InvalidArgumentException(sprintf(
                        '--product-file: invalid product ID "%s" on line %d of %s.',
                        $value,
                        $lineNumber,
                        $path
                    ));
                }
                $firstRow = false;
            }
        } finally {
            fclose($handle);
        }

        if (empty($ids)) {
            throw new \InvalidArgumentException(sprintf('--product-file: no product ID found in %s.', $path));
        }

        $ids = array_keys($ids);
        sort($ids);

        return $ids;
    }
}
