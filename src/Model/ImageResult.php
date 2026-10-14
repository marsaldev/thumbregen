<?php
/**
 * @author Marco Salvatore (marsaldev)
 * @license AFL-3.0
 */

namespace Marsaldev\Module\ThumbRegen\Model;

/**
 * Outcome of processing a single product image.
 */
class ImageResult
{
    /** @var int */
    private $imageId;

    /** @var int */
    private $productId;

    /** @var string|null */
    private $skipReason;

    /** @var string[] thumbnails written (or that would be written, in dry-run) */
    private $thumbnails = [];

    /** @var string[] old thumbnails deleted (or that would be deleted, in dry-run) */
    private $deleted = [];

    /** @var string[] */
    private $warnings = [];

    /** @var string[] */
    private $errors = [];

    /**
     * @param int $imageId
     * @param int $productId
     */
    public function __construct($imageId, $productId)
    {
        $this->imageId = (int) $imageId;
        $this->productId = (int) $productId;
    }

    /**
     * @return int
     */
    public function getImageId()
    {
        return $this->imageId;
    }

    /**
     * @return int
     */
    public function getProductId()
    {
        return $this->productId;
    }

    /**
     * @param string $reason
     *
     * @return $this
     */
    public function skip($reason)
    {
        $this->skipReason = $reason;

        return $this;
    }

    /**
     * @return bool
     */
    public function isSkipped()
    {
        return null !== $this->skipReason;
    }

    /**
     * @return string|null
     */
    public function getSkipReason()
    {
        return $this->skipReason;
    }

    /**
     * @param string $path
     */
    public function addThumbnail($path)
    {
        $this->thumbnails[] = $path;
    }

    /**
     * @return string[]
     */
    public function getThumbnails()
    {
        return $this->thumbnails;
    }

    /**
     * @param string $path
     */
    public function addDeleted($path)
    {
        $this->deleted[] = $path;
    }

    /**
     * @return string[]
     */
    public function getDeleted()
    {
        return $this->deleted;
    }

    /**
     * @param string $message
     */
    public function addWarning($message)
    {
        $this->warnings[] = $message;
    }

    /**
     * @return string[]
     */
    public function getWarnings()
    {
        return $this->warnings;
    }

    /**
     * @param string $message
     */
    public function addError($message)
    {
        $this->errors[] = $message;
    }

    /**
     * @return string[]
     */
    public function getErrors()
    {
        return $this->errors;
    }

    /**
     * @return bool
     */
    public function hasErrors()
    {
        return !empty($this->errors);
    }
}
