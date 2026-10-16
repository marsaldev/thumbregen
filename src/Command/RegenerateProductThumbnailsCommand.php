<?php
/**
 * @author Marco Salvatore (marsaldev)
 * @license AFL-3.0
 */

namespace Marsaldev\Module\ThumbRegen\Command;

use Cache;
use Configuration;
use Marsaldev\Module\ThumbRegen\Model\ImageResult;
use Marsaldev\Module\ThumbRegen\Model\RunReport;
use Marsaldev\Module\ThumbRegen\Model\SelectionCriteria;
use Marsaldev\Module\ThumbRegen\Service\DatabaseClient;
use Marsaldev\Module\ThumbRegen\Service\ImageTypeProvider;
use Marsaldev\Module\ThumbRegen\Service\ProductIdFileReader;
use Marsaldev\Module\ThumbRegen\Service\ProductSelector;
use Marsaldev\Module\ThumbRegen\Service\ThumbnailRegenerator;
use PrestaShop\PrestaShop\Adapter\LegacyContextLoader;
use Shop;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Validate;

class RegenerateProductThumbnailsCommand extends Command
{
    // Symfony 4.4 has no Command::SUCCESS / Command::FAILURE constants.
    const EXIT_SUCCESS = 0;
    const EXIT_FAILURE = 1;
    const EXIT_PARTIAL = 2;

    const DEFAULT_BATCH_SIZE = 50;
    const DEFAULT_MEMORY_LIMIT = '1G';

    // How many missing IDs of --product-file are listed in the warning.
    const MAX_LISTED_MISSING_IDS = 20;

    protected static $defaultName = 'thumbregen:products:regenerate';

    /** @var ProductSelector */
    private $productSelector;

    /** @var ThumbnailRegenerator */
    private $regenerator;

    /** @var ImageTypeProvider */
    private $imageTypeProvider;

    /** @var DatabaseClient */
    private $db;

    /** @var LegacyContextLoader */
    private $contextLoader;

    /** @var ProductIdFileReader */
    private $productIdFileReader;

    /** @var OutputInterface */
    private $errorOutput;

    /** @var ProgressBar|null */
    private $progressBar;

    public function __construct(
        ProductSelector $productSelector,
        ThumbnailRegenerator $regenerator,
        ImageTypeProvider $imageTypeProvider,
        DatabaseClient $db,
        LegacyContextLoader $contextLoader,
        ProductIdFileReader $productIdFileReader
    ) {
        $this->productSelector = $productSelector;
        $this->regenerator = $regenerator;
        $this->imageTypeProvider = $imageTypeProvider;
        $this->db = $db;
        $this->contextLoader = $contextLoader;
        $this->productIdFileReader = $productIdFileReader;

        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName(self::$defaultName)
            ->setDescription('Regenerates product image thumbnails, optionally limited to a product, a list of products, a category or some image types')
            ->addOption('product', null, InputOption::VALUE_REQUIRED, 'Only this product ID')
            ->addOption('product-file', null, InputOption::VALUE_REQUIRED, 'Only the product IDs listed in this CSV/text file (first column, one per row, header allowed)')
            ->addOption('category', null, InputOption::VALUE_REQUIRED, 'Only products associated with this category ID (category_product)')
            ->addOption('with-subcategories', null, InputOption::VALUE_NONE, 'With --category, also include products of its subcategories')
            ->addOption('type', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Image type name (e.g. home_default). Repeatable. Default: all product image types')
            ->addOption('shop-id', null, InputOption::VALUE_REQUIRED, 'Shop context in multistore (default: PS_SHOP_DEFAULT)')
            ->addOption('delete-old', null, InputOption::VALUE_NONE, 'Delete existing thumbnails of the selected types before regenerating (default: overwrite)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would be processed without writing or deleting anything')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Products per batch; memory is released between batches', self::DEFAULT_BATCH_SIZE)
            ->addOption('memory-limit', null, InputOption::VALUE_REQUIRED, 'PHP memory_limit for this run', self::DEFAULT_MEMORY_LIMIT)
            ->setHelp(<<<'HELP'
Regenerates the thumbnails of product images from the originals ({id_image}.jpg), using the image
types sizes, the image formats configured in Design > Image settings, the high-DPI setting and the
actionWatermark modules, like the back office does. Original images are never modified.

  <info>%command.full_name%</info>                                        all products, all types
  <info>%command.full_name% --product=42</info>                           one product
  <info>%command.full_name% --product-file=ids.csv</info>                 the products listed in a file
  <info>%command.full_name% --category=12 --with-subcategories</info>     a category and its subtree
  <info>%command.full_name% --type=home_default --type=large_default</info>
  <info>%command.full_name% --category=12 --type=home_default --dry-run</info>

Exit codes: 0 = success (warnings allowed), 1 = invalid arguments or fatal error,
2 = completed with errors on some images.
Use -v for the per-image detail, -vv for every file, -q to print errors only.
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);
        $this->errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $this->progressBar = null;

        try {
            $options = $this->readOptions($input);
        } catch (\InvalidArgumentException $e) {
            $this->writeError($e->getMessage());

            return self::EXIT_FAILURE;
        }

        $this->applyRuntimeLimits($options['memory_limit'], $io);

        try {
            $this->db->ensureConnection();
            $shopId = $this->loadShopContext($options['shop_id']);
            $imageTypes = $this->imageTypeProvider->resolve($options['types']);
            if (empty($imageTypes)) {
                throw new \InvalidArgumentException('No image type is enabled for products.');
            }
            $criteria = $this->buildCriteria($options, $shopId);
            if (null !== $options['shop_id'] && !$criteria->isMultistore()) {
                $io->warning('Multistore is not active: --shop-id only sets the configuration context, products and images are not filtered by shop.');
            }
            $missingProductIds = $this->productSelector->findMissingProductIds($criteria);
            $this->regenerator->prepare($shopId);

            if (!$this->regenerator->isProductImageDirWritable()) {
                $message = sprintf('The product images directory is missing or not writable: %s', _PS_PRODUCT_IMG_DIR_);
                if (!$options['dry_run']) {
                    throw new \InvalidArgumentException($message);
                }
                $io->warning($message);
            }

            $total = $this->productSelector->countProducts($criteria);
        } catch (\InvalidArgumentException $e) {
            $this->writeError($e->getMessage());

            return self::EXIT_FAILURE;
        } catch (\Throwable $e) {
            $this->writeError(sprintf('Fatal error during initialization: %s', $e->getMessage()));

            return self::EXIT_FAILURE;
        }

        $this->printHeader($io, $options, $criteria, $imageTypes, $total);
        $this->printMissingProductIds($io, $criteria, $missingProductIds);

        if (0 === $total) {
            $io->warning('No product matches the selection.');

            return self::EXIT_SUCCESS;
        }

        $report = new RunReport();
        // Without a terminal (cron, pipes) the bar cannot be cleared, so with -v the per-image lines replace it.
        if (!$options['dry_run'] && (!$output->isVerbose() || $output->isDecorated())) {
            $this->progressBar = $io->createProgressBar($total);
            $this->progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');
            $this->progressBar->start();
        }

        $fatal = null;
        try {
            $this->processAll($output, $criteria, $imageTypes, $options, $report);
        } catch (\Throwable $e) {
            $fatal = $e;
        }

        if (null !== $this->progressBar) {
            if (null === $fatal) {
                $this->progressBar->finish();
            }
            $output->writeln('', OutputInterface::VERBOSITY_NORMAL);
            $this->progressBar = null;
        }

        if (null !== $fatal) {
            $report->addError(sprintf('Fatal error, run interrupted: %s', $fatal->getMessage()));
        }

        $this->printSummary($io, $report, $options['dry_run'], $options['delete_old']);

        if (null !== $fatal) {
            return self::EXIT_FAILURE;
        }

        return $report->getErrorCount() > 0 ? self::EXIT_PARTIAL : self::EXIT_SUCCESS;
    }

    /**
     * @param OutputInterface $output
     * @param SelectionCriteria $criteria
     * @param array[] $imageTypes
     * @param array $options
     * @param RunReport $report
     */
    private function processAll(OutputInterface $output, SelectionCriteria $criteria, array $imageTypes, array $options, RunReport $report)
    {
        $lastProductId = 0;

        while (true) {
            $this->db->ensureConnection();

            $productIds = $this->productSelector->fetchProductIds($criteria, $lastProductId, $options['batch_size']);
            if (empty($productIds)) {
                break;
            }
            $lastProductId = (int) end($productIds);
            $imagesByProduct = $this->productSelector->fetchImageIdsByProduct($productIds, $criteria);

            foreach ($productIds as $productId) {
                $imageIds = isset($imagesByProduct[$productId]) ? $imagesByProduct[$productId] : [];
                $report->addProduct(count($imageIds));

                if ($options['dry_run']) {
                    $output->writeln(sprintf(
                        'Product #%d: %s',
                        $productId,
                        empty($imageIds) ? 'no images' : count($imageIds) . ' image(s) [' . implode(', ', $imageIds) . ']'
                    ));
                }

                foreach ($imageIds as $imageId) {
                    $result = $this->regenerator->processImage($imageId, $productId, $imageTypes, $options['delete_old'], $options['dry_run']);
                    $report->addImageResult($result);
                    $this->printImageResult($output, $result, $options['dry_run']);
                    unset($result);
                }

                if (null !== $this->progressBar) {
                    $this->progressBar->advance();
                }
            }

            // Release what the batch loaded: our arrays, ObjectModel/Db local cache, cyclic garbage.
            unset($productIds, $imagesByProduct);
            Cache::clean('*');
            gc_collect_cycles();
        }
    }

    /**
     * @param InputInterface $input
     *
     * @return array
     *
     * @throws \InvalidArgumentException
     */
    private function readOptions(InputInterface $input)
    {
        $productId = $this->parsePositiveInt($input->getOption('product'), 'product');
        $categoryId = $this->parsePositiveInt($input->getOption('category'), 'category');
        $productFile = trim((string) $input->getOption('product-file'));
        $withSubcategories = (bool) $input->getOption('with-subcategories');

        if (count(array_filter([null !== $productId, null !== $categoryId, '' !== $productFile])) > 1) {
            throw new \InvalidArgumentException('--product, --product-file and --category cannot be used together.');
        }
        if ($withSubcategories && null === $categoryId) {
            throw new \InvalidArgumentException('--with-subcategories requires --category.');
        }

        // Accept both --type=a --type=b and --type=a,b
        $types = [];
        foreach ((array) $input->getOption('type') as $value) {
            foreach (explode(',', (string) $value) as $name) {
                $name = trim($name);
                if ('' !== $name) {
                    $types[] = $name;
                }
            }
        }

        $batchSize = $this->parsePositiveInt($input->getOption('batch-size'), 'batch-size');
        $memoryLimit = trim((string) $input->getOption('memory-limit'));
        if (!preg_match('/^(-1|\d+[KMG]?)$/i', $memoryLimit)) {
            throw new \InvalidArgumentException(sprintf('Invalid --memory-limit "%s": use a value like 512M, 2G or -1.', $memoryLimit));
        }

        $productIds = null;
        if ('' !== $productFile) {
            $productIds = $this->productIdFileReader->read($productFile);
            $realPath = realpath($productFile);
            $productFile = false !== $realPath ? $realPath : $productFile;
        }

        return [
            'product_id' => $productId,
            'product_file' => '' !== $productFile ? $productFile : null,
            'product_ids' => $productIds,
            'category_id' => $categoryId,
            'with_subcategories' => $withSubcategories,
            'types' => array_values(array_unique($types)),
            'shop_id' => $this->parsePositiveInt($input->getOption('shop-id'), 'shop-id'),
            'delete_old' => (bool) $input->getOption('delete-old'),
            'dry_run' => (bool) $input->getOption('dry-run'),
            'batch_size' => null !== $batchSize ? $batchSize : self::DEFAULT_BATCH_SIZE,
            'memory_limit' => $memoryLimit,
        ];
    }

    /**
     * @param mixed $value
     * @param string $option
     *
     * @return int|null
     *
     * @throws \InvalidArgumentException
     */
    private function parsePositiveInt($value, $option)
    {
        if (null === $value || '' === $value) {
            return null;
        }
        if (!ctype_digit((string) $value) || (int) $value <= 0) {
            throw new \InvalidArgumentException(sprintf('--%s must be a positive integer, "%s" given.', $option, $value));
        }

        return (int) $value;
    }

    /**
     * @param string $memoryLimit
     * @param SymfonyStyle $io
     */
    private function applyRuntimeLimits($memoryLimit, SymfonyStyle $io)
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        } else {
            $io->warning('set_time_limit() is disabled: the run is subject to max_execution_time (' . ini_get('max_execution_time') . ').');
        }

        $applied = function_exists('ini_set') && false !== @ini_set('memory_limit', $memoryLimit);
        if (!$applied || 0 !== strcasecmp((string) ini_get('memory_limit'), $memoryLimit)) {
            $io->warning(sprintf(
                'Could not set memory_limit to %s (ini_set disabled or refused), continuing with %s.',
                $memoryLimit,
                ini_get('memory_limit')
            ));
        }
    }

    /**
     * @param int|null $shopId
     *
     * @return int
     *
     * @throws \InvalidArgumentException
     */
    private function loadShopContext($shopId)
    {
        if (null === $shopId) {
            $shopId = (int) Configuration::get('PS_SHOP_DEFAULT');
        }

        $shop = new Shop($shopId);
        if (!Validate::isLoadedObject($shop)) {
            throw new \InvalidArgumentException(sprintf('Shop #%d does not exist.', $shopId));
        }

        $this->contextLoader->loadShopContext($shopId);

        return $shopId;
    }

    /**
     * @param array $options
     * @param int $shopId
     *
     * @return SelectionCriteria
     *
     * @throws \InvalidArgumentException
     */
    private function buildCriteria(array $options, $shopId)
    {
        $multistore = Shop::isFeatureActive();

        if (null !== $options['product_id']) {
            if (!$this->productSelector->productExists($options['product_id'])) {
                throw new \InvalidArgumentException(sprintf('Product #%d does not exist.', $options['product_id']));
            }
            if ($multistore && !$this->productSelector->productBelongsToShop($options['product_id'], $shopId)) {
                throw new \InvalidArgumentException(sprintf('Product #%d is not associated with shop #%d.', $options['product_id'], $shopId));
            }

            return new SelectionCriteria($shopId, $multistore, $options['product_id']);
        }

        if (null !== $options['product_ids']) {
            return new SelectionCriteria($shopId, $multistore, null, null, null, null, $options['product_ids']);
        }

        if (null !== $options['category_id']) {
            $category = $this->productSelector->getCategory($options['category_id']);
            if (null === $category) {
                throw new \InvalidArgumentException(sprintf('Category #%d does not exist.', $options['category_id']));
            }
            if (!$options['with_subcategories']) {
                return new SelectionCriteria($shopId, $multistore, null, $category['id_category']);
            }
            if ($category['nleft'] <= 0 || $category['nright'] <= $category['nleft']) {
                throw new \InvalidArgumentException(sprintf(
                    'Category #%d has no valid nested set bounds (nleft=%d, nright=%d): regenerate the category tree before using --with-subcategories.',
                    $category['id_category'],
                    $category['nleft'],
                    $category['nright']
                ));
            }

            return new SelectionCriteria($shopId, $multistore, null, $category['id_category'], $category['nleft'], $category['nright']);
        }

        return new SelectionCriteria($shopId, $multistore);
    }

    /**
     * @param SymfonyStyle $io
     * @param array $options
     * @param SelectionCriteria $criteria
     * @param array[] $imageTypes
     * @param int $total
     */
    private function printHeader(SymfonyStyle $io, array $options, SelectionCriteria $criteria, array $imageTypes, $total)
    {
        if (null !== $criteria->getProductId()) {
            $selection = sprintf('product #%d', $criteria->getProductId());
        } elseif (null !== $criteria->getProductIds()) {
            $selection = sprintf('%d product ID(s) from %s', count($criteria->getProductIds()), $options['product_file']);
        } elseif (null !== $criteria->getCategoryId()) {
            $selection = sprintf(
                'category #%d %s subcategories',
                $criteria->getCategoryId(),
                $criteria->includesSubcategories() ? 'with' : 'without'
            );
        } else {
            $selection = 'whole catalog';
        }

        $watermarkModules = $this->regenerator->getWatermarkModules();

        $io->title($options['dry_run'] ? 'Product thumbnails regeneration (DRY RUN)' : 'Product thumbnails regeneration');
        $io->listing([
            sprintf('Selection: %s, shop #%d%s', $selection, $criteria->getShopId(), $criteria->isMultistore() ? ' (multistore)' : ''),
            sprintf('Products: %d', $total),
            sprintf('Image types: %s', implode(', ', array_column($imageTypes, 'name'))),
            sprintf('Formats: %s, high DPI: %s', implode(', ', $this->regenerator->getGenerationFormats()), $this->regenerator->isHighDpiEnabled() ? 'yes' : 'no'),
            sprintf('Watermark modules: %s', empty($watermarkModules) ? 'none' : implode(', ', $watermarkModules)),
            sprintf('Existing thumbnails: %s', $options['delete_old'] ? 'deleted first' : 'overwritten'),
            sprintf('Batch size: %d, memory limit: %s', $options['batch_size'], ini_get('memory_limit')),
        ]);
    }

    /**
     * IDs of --product-file that are not processed: a warning, not an error, since the file may be older than the catalog.
     *
     * @param SymfonyStyle $io
     * @param SelectionCriteria $criteria
     * @param int[] $missingProductIds
     */
    private function printMissingProductIds(SymfonyStyle $io, SelectionCriteria $criteria, array $missingProductIds)
    {
        if (empty($missingProductIds)) {
            return;
        }

        $listed = array_slice($missingProductIds, 0, self::MAX_LISTED_MISSING_IDS);
        $more = count($missingProductIds) - count($listed);
        $io->warning(sprintf(
            '%d product ID(s) of the file do not exist%s and are ignored: %s%s',
            count($missingProductIds),
            $criteria->isMultistore() ? sprintf(' or are not associated with shop #%d', $criteria->getShopId()) : '',
            implode(', ', $listed),
            $more > 0 ? sprintf(' and %d more', $more) : ''
        ));
    }

    /**
     * -v: one line per image (plus the planned files in dry-run); -vv: every file;
     * errors are always printed at the end.
     *
     * @param OutputInterface $output
     * @param ImageResult $result
     * @param bool $dryRun
     */
    private function printImageResult(OutputInterface $output, ImageResult $result, $dryRun)
    {
        if (!$output->isVerbose()) {
            return;
        }

        $lines = [];
        $prefix = sprintf('  product #%d, image #%d: ', $result->getProductId(), $result->getImageId());

        if ($result->isSkipped()) {
            $lines[] = '<comment>' . $prefix . 'skipped, ' . $result->getSkipReason() . '</comment>';
        } else {
            $lines[] = $prefix . sprintf(
                '%d thumbnail(s) %s, %d old deleted%s',
                count($result->getThumbnails()),
                $dryRun ? 'to generate' : 'generated',
                count($result->getDeleted()),
                $result->hasErrors() ? sprintf(', <error>%d error(s)</error>', count($result->getErrors())) : ''
            );
        }

        if ($dryRun || $output->isVeryVerbose()) {
            foreach ($result->getDeleted() as $file) {
                $lines[] = '      - ' . $file;
            }
            foreach ($result->getThumbnails() as $file) {
                $lines[] = '      + ' . $file;
            }
        }
        foreach ($result->getWarnings() as $warning) {
            $lines[] = '<comment>      warning: ' . $warning . '</comment>';
        }
        foreach ($result->getErrors() as $error) {
            $lines[] = '<error>      error: ' . $error . '</error>';
        }

        if (null !== $this->progressBar) {
            $this->progressBar->clear();
        }
        $output->writeln($lines);
        if (null !== $this->progressBar) {
            $this->progressBar->display();
        }
    }

    /**
     * @param SymfonyStyle $io
     * @param RunReport $report
     * @param bool $dryRun
     * @param bool $deleteOld
     */
    private function printSummary(SymfonyStyle $io, RunReport $report, $dryRun, $deleteOld)
    {
        $rows = [
            ['Products processed', $report->getProducts()],
            ['Products without images', $report->getProductsWithoutImages()],
            ['Images processed', $report->getImages()],
            [$dryRun ? 'Thumbnails to generate' : 'Thumbnails generated', $report->getThumbnails()],
        ];
        if ($deleteOld) {
            $rows[] = [$dryRun ? 'Old thumbnails to delete' : 'Old thumbnails deleted', $report->getDeleted()];
        }
        $rows[] = ['Images skipped (missing/corrupt original)', $report->getSkipped()];
        $rows[] = ['Warnings', $report->getWarningCount()];
        $rows[] = ['Errors', $report->getErrorCount()];
        if ($this->db->getReconnections() > 0) {
            $rows[] = ['DB reconnections', $this->db->getReconnections()];
        }
        $rows[] = ['Total time', Helper::formatTime($report->getElapsedTime())];
        $rows[] = ['Peak memory', Helper::formatMemory(memory_get_peak_usage(true))];

        $io->section('Summary');
        $io->table(['', ''], $rows);

        $this->printMessages($io, 'Skipped images', $report->getSkippedMessages(), $report->getSkipped());
        $this->printMessages($io, 'Warnings', $report->getWarningMessages(), $report->getWarningCount());

        if ($report->getErrorCount() > 0) {
            $this->writeError(sprintf('%d error(s):', $report->getErrorCount()));
            foreach ($report->getErrorMessages() as $message) {
                $this->writeError('  ' . $message);
            }
            if ($report->getErrorCount() > count($report->getErrorMessages())) {
                $this->writeError(sprintf('  ... and %d more', $report->getErrorCount() - count($report->getErrorMessages())));
            }
        } elseif ($dryRun) {
            $io->success('Dry run completed, nothing was written.');
        } else {
            $io->success('Thumbnails regenerated.');
        }
    }

    /**
     * @param SymfonyStyle $io
     * @param string $title
     * @param string[] $messages
     * @param int $total
     */
    private function printMessages(SymfonyStyle $io, $title, array $messages, $total)
    {
        if (empty($messages)) {
            return;
        }

        $io->text(sprintf('<comment>%s (%d):</comment>', $title, $total));
        foreach ($messages as $message) {
            $io->text('  ' . $message);
        }
        if ($total > count($messages)) {
            $io->text(sprintf('  ... and %d more', $total - count($messages)));
        }
        $io->newLine();
    }

    /**
     * Errors are written on stderr and are shown even with -q.
     *
     * @param string $message
     */
    private function writeError($message)
    {
        $this->errorOutput->writeln('<error>' . $message . '</error>', OutputInterface::VERBOSITY_QUIET);
    }
}
