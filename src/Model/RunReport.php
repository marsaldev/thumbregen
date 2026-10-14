<?php
/**
 * @author Marco Salvatore (marsaldev)
 * @license AFL-3.0
 */

namespace Marsaldev\Module\ThumbRegen\Model;

/**
 * Counters and messages collected during a run, used for the final summary and the exit code.
 * Messages are capped so that a catalog full of broken images cannot exhaust memory.
 */
class RunReport
{
    const MAX_STORED_MESSAGES = 200;

    /** @var float */
    private $startedAt;

    /** @var int */
    private $products = 0;

    /** @var int */
    private $productsWithoutImages = 0;

    /** @var int */
    private $images = 0;

    /** @var int */
    private $thumbnails = 0;

    /** @var int */
    private $deleted = 0;

    /** @var int */
    private $skipped = 0;

    /** @var int */
    private $errorCount = 0;

    /** @var int */
    private $warningCount = 0;

    /** @var string[] */
    private $skippedMessages = [];

    /** @var string[] */
    private $errorMessages = [];

    /** @var string[] */
    private $warningMessages = [];

    public function __construct()
    {
        $this->startedAt = microtime(true);
    }

    /**
     * @param int $imageCount
     */
    public function addProduct($imageCount)
    {
        ++$this->products;
        if (0 === (int) $imageCount) {
            ++$this->productsWithoutImages;
        }
    }

    public function addImageResult(ImageResult $result)
    {
        ++$this->images;
        $prefix = sprintf('Product #%d, image #%d: ', $result->getProductId(), $result->getImageId());

        if ($result->isSkipped()) {
            ++$this->skipped;
            $this->store($this->skippedMessages, $prefix . $result->getSkipReason());
        }

        $this->thumbnails += count($result->getThumbnails());
        $this->deleted += count($result->getDeleted());

        foreach ($result->getWarnings() as $warning) {
            ++$this->warningCount;
            $this->store($this->warningMessages, $prefix . $warning);
        }
        foreach ($result->getErrors() as $error) {
            ++$this->errorCount;
            $this->store($this->errorMessages, $prefix . $error);
        }
    }

    /**
     * @param string $message
     */
    public function addError($message)
    {
        ++$this->errorCount;
        $this->store($this->errorMessages, $message);
    }

    /**
     * @return int
     */
    public function getProducts()
    {
        return $this->products;
    }

    /**
     * @return int
     */
    public function getProductsWithoutImages()
    {
        return $this->productsWithoutImages;
    }

    /**
     * @return int
     */
    public function getImages()
    {
        return $this->images;
    }

    /**
     * @return int
     */
    public function getThumbnails()
    {
        return $this->thumbnails;
    }

    /**
     * @return int
     */
    public function getDeleted()
    {
        return $this->deleted;
    }

    /**
     * @return int
     */
    public function getSkipped()
    {
        return $this->skipped;
    }

    /**
     * @return int
     */
    public function getErrorCount()
    {
        return $this->errorCount;
    }

    /**
     * @return int
     */
    public function getWarningCount()
    {
        return $this->warningCount;
    }

    /**
     * @return string[]
     */
    public function getSkippedMessages()
    {
        return $this->skippedMessages;
    }

    /**
     * @return string[]
     */
    public function getErrorMessages()
    {
        return $this->errorMessages;
    }

    /**
     * @return string[]
     */
    public function getWarningMessages()
    {
        return $this->warningMessages;
    }

    /**
     * @return float seconds
     */
    public function getElapsedTime()
    {
        return microtime(true) - $this->startedAt;
    }

    /**
     * @param string[] $messages
     * @param string $message
     */
    private function store(array &$messages, $message)
    {
        if (count($messages) < self::MAX_STORED_MESSAGES) {
            $messages[] = $message;
        }
    }
}
