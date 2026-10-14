<?php
/**
 * @author Marco Salvatore (marsaldev)
 * @license AFL-3.0
 */

namespace Marsaldev\Module\ThumbRegen\Command;

use Context;
use Marsaldev\Module\ThumbRegen\Service\ImageTypeProvider;
use Marsaldev\Module\ThumbRegen\Service\ThumbnailRegenerator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class ListImageTypesCommand extends Command
{
    protected static $defaultName = 'thumbregen:products:list-types';

    /** @var ImageTypeProvider */
    private $imageTypeProvider;

    /** @var ThumbnailRegenerator */
    private $regenerator;

    public function __construct(ImageTypeProvider $imageTypeProvider, ThumbnailRegenerator $regenerator)
    {
        $this->imageTypeProvider = $imageTypeProvider;
        $this->regenerator = $regenerator;

        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName(self::$defaultName)
            ->setDescription('Lists the image types available for products, to be used with thumbregen:products:regenerate --type');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);

        $types = $this->imageTypeProvider->getProductImageTypes();
        if (empty($types)) {
            $io->warning('No image type is enabled for products.');

            return 0;
        }

        $this->regenerator->prepare((int) Context::getContext()->shop->id);
        $highDpi = $this->regenerator->isHighDpiEnabled();

        $rows = [];
        foreach ($types as $type) {
            $rows[] = [
                $type['name'],
                (int) $type['width'] . 'x' . (int) $type['height'],
                $highDpi ? ((int) $type['width'] * 2) . 'x' . ((int) $type['height'] * 2) : '-',
            ];
        }

        $io->table(['Name', 'Size', 'High DPI (2x)'], $rows);
        $io->text(sprintf(
            'Generated formats: %s. High DPI: %s.',
            implode(', ', $this->regenerator->getGenerationFormats()),
            $highDpi ? 'enabled' : 'disabled'
        ));

        return 0;
    }
}
