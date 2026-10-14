<?php
/**
 * @author Marco Salvatore (marsaldev)
 * @license AFL-3.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

/**
 * CLI-only module: it registers the thumbregen:* Symfony console commands (see config/services.yml).
 * No configuration page, no hooks, no database tables.
 */
class Thumbregen extends Module
{
    public function __construct()
    {
        $this->name = 'thumbregen';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'Marco Salvatore';
        $this->need_instance = 0;
        $this->bootstrap = false;
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];

        parent::__construct();

        $this->displayName = $this->trans('Product thumbnails regeneration (CLI)', [], 'Modules.Thumbregen.Admin');
        $this->description = $this->trans(
            'Adds the thumbregen:products:regenerate and thumbregen:products:list-types console commands to regenerate product thumbnails by product, category and image type.',
            [],
            'Modules.Thumbregen.Admin'
        );
    }

    public function install()
    {
        return parent::install();
    }

    public function uninstall()
    {
        return parent::uninstall();
    }
}
