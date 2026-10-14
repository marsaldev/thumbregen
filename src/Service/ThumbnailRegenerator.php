<?php
/**
 * @author Marco Salvatore (marsaldev)
 * @license AFL-3.0
 */

namespace Marsaldev\Module\ThumbRegen\Service;

use Configuration;
use Image;
use ImageManager;
use Marsaldev\Module\ThumbRegen\Model\ImageResult;
use Module;
use PrestaShop\PrestaShop\Adapter\Configuration as ConfigurationAdapter;

/**
 * Regenerates the thumbnails of a single product image following the same path as the core
 * (AdminImagesController::_regenerateNewImages + _regenerateWatermark):
 * ImageManager::resize() with the ImageType sizes, for every configured format and high-DPI variant,
 * then the actionWatermark modules. The original {id_image}.jpg is only read, never written.
 */
class ThumbnailRegenerator
{
    /**
     * Only exists on PrestaShop 8.1+, where multiple image formats (webp, avif, png) were introduced.
     */
    const FORMAT_CONFIGURATION_CLASS = 'PrestaShop\PrestaShop\Core\Image\ImageFormatConfiguration';

    const DEFAULT_FORMAT = 'jpg';

    /**
     * Extensions removed by --delete-old, same list as AdminImagesController::_deleteOldImages().
     */
    const DELETABLE_EXTENSIONS = ['jpg', 'png', 'webp', 'avif'];

    /** @var DatabaseClient */
    private $db;

    /** @var string[] */
    private $formats = [self::DEFAULT_FORMAT];

    /** @var bool */
    private $highDpi = false;

    /** @var string[] */
    private $watermarkModules = [];

    public function __construct(DatabaseClient $db)
    {
        $this->db = $db;
    }

    /**
     * Reads the image settings of the current shop context. Call it once the shop context is loaded.
     *
     * @param int $shopId
     */
    public function prepare($shopId)
    {
        $this->formats = $this->resolveGenerationFormats();
        $this->highDpi = (bool) Configuration::get('PS_HIGHT_DPI');
        $this->watermarkModules = $this->resolveWatermarkModules($shopId);
    }

    /**
     * @return string[]
     */
    public function getGenerationFormats()
    {
        return $this->formats;
    }

    /**
     * @return bool
     */
    public function isHighDpiEnabled()
    {
        return $this->highDpi;
    }

    /**
     * @return string[]
     */
    public function getWatermarkModules()
    {
        return $this->watermarkModules;
    }

    /**
     * @return bool
     */
    public function isProductImageDirWritable()
    {
        return is_dir(_PS_PRODUCT_IMG_DIR_) && is_writable(_PS_PRODUCT_IMG_DIR_);
    }

    /**
     * @param int $imageId
     * @param int $productId
     * @param array[] $imageTypes rows from ImageTypeProvider
     * @param bool $deleteOld remove existing thumbnails of the selected types before regenerating
     * @param bool $dryRun only compute what would be done, never touch the filesystem
     *
     * @return ImageResult
     */
    public function processImage($imageId, $productId, array $imageTypes, $deleteOld, $dryRun)
    {
        $result = new ImageResult($imageId, $productId);

        // Built by hand instead of new Image($id): we only need the path, no DB round-trip per image.
        $image = new Image();
        $image->id = (int) $imageId;
        $image->id_product = (int) $productId;
        $basePath = _PS_PRODUCT_IMG_DIR_ . $image->getExistingImgPath();
        $source = $basePath . '.jpg';
        unset($image);

        clearstatcache(true, $source);
        if (!is_file($source) || !filesize($source)) {
            return $result->skip(sprintf('original image missing or empty (%s)', $source));
        }
        if (false === @getimagesize($source)) {
            return $result->skip(sprintf('original image corrupt or not an image (%s)', $source));
        }

        $targets = $this->buildTargets($basePath, $imageTypes);

        if ($dryRun) {
            if ($deleteOld) {
                foreach ($this->findExistingThumbnails($basePath, $imageTypes) as $file) {
                    $result->addDeleted($file);
                }
            }
            foreach ($targets as $target) {
                $result->addThumbnail($target['path']);
            }

            return $result;
        }

        if (!is_writable(dirname($source))) {
            $result->addError(sprintf('image folder is not writable (%s)', dirname($source)));

            return $result;
        }

        if ($deleteOld) {
            foreach ($this->findExistingThumbnails($basePath, $imageTypes) as $file) {
                if (@unlink($file)) {
                    $result->addDeleted($file);
                } else {
                    $result->addError(sprintf('cannot delete old thumbnail (%s)', $file));
                }
            }
        }

        foreach ($targets as $target) {
            $messages = [];
            $resized = $this->guard(function () use ($source, $target) {
                return ImageManager::resize(
                    $source,
                    $target['path'],
                    $target['width'],
                    $target['height'],
                    $target['format'],
                    // As in the core: for jpg ImageManager picks the real format from PS_IMAGE_QUALITY,
                    // webp/avif/png are forced to the requested format.
                    self::DEFAULT_FORMAT !== $target['format']
                );
            }, $messages);

            if ($resized) {
                $result->addThumbnail($target['path']);
            } else {
                $result->addError(sprintf(
                    'resize failed for %s%s',
                    $target['path'],
                    empty($messages) ? ' (corrupt original or bad permissions)' : ' (' . implode('; ', $messages) . ')'
                ));
            }
        }

        if (!empty($result->getThumbnails())) {
            $this->applyWatermark($result, $imageTypes);
        }

        return $result;
    }

    /**
     * Single place where core version differences are handled.
     *
     * PS 8.0: only .jpg thumbnails (whose real encoding still follows PS_IMAGE_QUALITY).
     * PS 8.1+: ImageFormatConfiguration returns jpg plus the formats enabled in Design > Image settings
     * (when the multiple_image_format feature flag is on).
     *
     * @return string[]
     */
    private function resolveGenerationFormats()
    {
        if (!class_exists(self::FORMAT_CONFIGURATION_CLASS)) {
            return [self::DEFAULT_FORMAT];
        }

        $class = self::FORMAT_CONFIGURATION_CLASS;
        $formatConfiguration = new $class(new ConfigurationAdapter());
        $formats = $formatConfiguration->getGenerationFormats();

        return empty($formats) ? [self::DEFAULT_FORMAT] : array_values($formats);
    }

    /**
     * Same lookup as AdminImagesController::_regenerateWatermark(), restricted to the current shop.
     *
     * @param int $shopId
     *
     * @return string[]
     */
    private function resolveWatermarkModules($shopId)
    {
        $rows = $this->db->executeS('
            SELECT m.`name`
            FROM `' . _DB_PREFIX_ . 'module` m
            INNER JOIN `' . _DB_PREFIX_ . 'hook_module` hm ON (hm.`id_module` = m.`id_module` AND hm.`id_shop` = ' . (int) $shopId . ')
            INNER JOIN `' . _DB_PREFIX_ . 'hook` h ON (h.`id_hook` = hm.`id_hook`)
            WHERE h.`name` = \'actionWatermark\' AND m.`active` = 1
            ORDER BY hm.`position` ASC');

        return array_values(array_unique(array_column($rows, 'name')));
    }

    /**
     * @param string $basePath image path without extension
     * @param array[] $imageTypes
     *
     * @return array[] path, width, height, format
     */
    private function buildTargets($basePath, array $imageTypes)
    {
        $targets = [];
        foreach ($imageTypes as $imageType) {
            $name = stripslashes($imageType['name']);
            foreach ($this->formats as $format) {
                $targets[] = [
                    'path' => $basePath . '-' . $name . '.' . $format,
                    'width' => (int) $imageType['width'],
                    'height' => (int) $imageType['height'],
                    'format' => $format,
                ];
                if ($this->highDpi) {
                    $targets[] = [
                        'path' => $basePath . '-' . $name . '2x.' . $format,
                        'width' => (int) $imageType['width'] * 2,
                        'height' => (int) $imageType['height'] * 2,
                        'format' => $format,
                    ];
                }
            }
        }

        return $targets;
    }

    /**
     * Existing thumbnails of the given types only ({id}-{type}[2x].{ext}), other types are left alone.
     *
     * @param string $basePath
     * @param array[] $imageTypes
     *
     * @return string[]
     */
    private function findExistingThumbnails($basePath, array $imageTypes)
    {
        $files = [];
        foreach ($imageTypes as $imageType) {
            $name = stripslashes($imageType['name']);
            foreach (['', '2x'] as $suffix) {
                foreach (self::DELETABLE_EXTENSIONS as $extension) {
                    $file = $basePath . '-' . $name . $suffix . '.' . $extension;
                    if (is_file($file)) {
                        $files[] = $file;
                    }
                }
            }
        }

        return $files;
    }

    /**
     * Calls hookActionWatermark directly on the modules registered to it, like the core does after
     * regenerating product thumbnails, passing only the selected image types.
     *
     * @param ImageResult $result
     * @param array[] $imageTypes
     */
    private function applyWatermark(ImageResult $result, array $imageTypes)
    {
        foreach ($this->watermarkModules as $moduleName) {
            $module = Module::getInstanceByName($moduleName);
            if (!$module || !is_callable([$module, 'hookActionWatermark'])) {
                continue;
            }

            $params = [
                'id_image' => $result->getImageId(),
                'id_product' => $result->getProductId(),
                'image_type' => $imageTypes,
            ];
            $messages = [];
            $thrown = null;
            $returned = $this->guard(function () use ($module, $params) {
                return $module->hookActionWatermark($params);
            }, $messages, $thrown);

            if (null !== $thrown) {
                $result->addError(sprintf('watermark module "%s" failed: %s', $moduleName, $thrown->getMessage()));
            } elseif (false === $returned) {
                $result->addWarning(sprintf(
                    'watermark module "%s" reported a failure%s',
                    $moduleName,
                    empty($messages) ? '' : ' (' . implode('; ', $messages) . ')'
                ));
            }
        }
    }

    /**
     * Runs third-party/core code so that a PHP warning or an exception on one image cannot stop the run
     * (in debug mode Symfony's ErrorHandler turns warnings into exceptions).
     * If the call failed because the DB connection dropped (e.g. a module querying the database),
     * the connection is re-opened and the call is retried once: both resize and watermark are idempotent.
     *
     * @param callable $callable
     * @param string[] $messages warnings and exception messages raised during the call
     * @param \Throwable|null $thrown
     *
     * @return mixed false when an exception was thrown
     */
    private function guard(callable $callable, array &$messages, &$thrown = null)
    {
        $returned = $this->guardOnce($callable, $messages, $thrown);

        if (null !== $thrown && $this->db->isConnectionError($thrown)) {
            try {
                $this->db->recover();
            } catch (\Throwable $e) {
                $messages[] = 'database reconnection failed: ' . $e->getMessage();

                return $returned;
            }
            $thrown = null;
            $returned = $this->guardOnce($callable, $messages, $thrown);
        }

        return $returned;
    }

    /**
     * @param callable $callable
     * @param string[] $messages
     * @param \Throwable|null $thrown
     *
     * @return mixed
     */
    private function guardOnce(callable $callable, array &$messages, &$thrown = null)
    {
        $collected = [];
        set_error_handler(function ($errno, $errstr) use (&$collected) {
            // Respect the @ operator and ignore deprecations.
            if (!(error_reporting() & $errno) || in_array($errno, [E_DEPRECATED, E_USER_DEPRECATED], true)) {
                return true;
            }
            $collected[] = $errstr;

            return true;
        });

        try {
            $returned = $callable();
        } catch (\Throwable $e) {
            $thrown = $e;
            $collected[] = $e->getMessage();
            $returned = false;
        } finally {
            restore_error_handler();
        }

        $messages = array_values(array_unique($collected));

        return $returned;
    }
}
