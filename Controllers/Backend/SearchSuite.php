<?php

use GuzzleHttp\Client;
use GuzzleHttp\Message\Request;
use GuzzleHttp\Stream\Stream;
use LuigisboxSearchSuiteShopware5\Models\Helper as H;
use \Shopware\Components\CSRFWhitelistAware;
use Shopware\Components\Routing\Context;
use Shopware\Models\Article\Article;
use Shopware\Models\Shop\Shop;

class Shopware_Controllers_Backend_SearchSuite extends Enlight_Controller_Action implements CSRFWhitelistAware
{
    const SYNC_ENABLED_KEY = 'luigisBoxCatalogSynchronization';
    const API_KEY = 'luigisBoxApiKey';
    const TRACKER_ID_KEY = 'luigisBoxTrackerID';

    const API_CONTENT_ENDPOINT = 'url_content';
    const API_CONTENT_DELETE_ENDPOINT = 'url_content_delete';
    const API_COMMIT_ENDPOINT = 'url_commit';

    const API_CONTENT_URI = 'https://live.luigisbox.com/v1/content';
    const API_CONTENT_COMMIT_URI = 'https://live.luigisbox.com/v1/content/commit';

    const TYPE_ITEM = 'item';
    const TYPE_CATEGORY = 'category';

    const LOGFILE = 'update.txt';

    private $indexer;

    public function indexAction()
    {

    }

    public function listAction()
    {
        $filePath = $this->getLogFilePath();

        if (!$this->isConfigured() || H::isIndexRunning($filePath)) {
            $this->writeLog('Prevent indexing.');
            return;
        }

        H::markIndexRunning($filePath);

        $filePath = $this->getLogFilePath();

        $this->indexer = $this->container->get('luigisbox_search_suite.indexer');
        $this->indexer->setFilePath($filePath);
        $success = $this->indexer->allContentUpdate();

        H::markIndexFinished($filePath);

        $this->View()->assign([
            'log' => $success ? 'Successfully updated all content.' : 'Failed to update content.',
        ]);
    }

    public function getWhitelistedCSRFActions()
    {
        return [
            'index'
        ];
    }

    public function postDispatch()
    {
        $csrfToken = $this->container->get('BackendSession')->offsetGet('X-CSRF-Token');
        $this->View()->assign([ 'csrfToken' => $csrfToken ]);
    }

    public function isConfigured()
    {
        if (!$this->isEnabled()) {
            return false;
        }

        $trackerId = $this->getTrackerId();
        if (empty($trackerId)) {
            return false;
        }

        $apiKey = $this->getApiKey();
        if (empty($apiKey)) {
            return false;
        }

        return true;
    }

    public function isEnabled()
    {
        return Shopware()->Config()->getByNamespace('LuigisboxSearchSuiteShopware5', self::SYNC_ENABLED_KEY);
    }

    public function getApiKey()
    {
        return Shopware()->Config()->getByNamespace('LuigisboxSearchSuiteShopware5', self::API_KEY);
    }

    public function getTrackerId()
    {
        return Shopware()->Config()->getByNamespace('LuigisboxSearchSuiteShopware5', self::TRACKER_ID_KEY);
    }

    public function getLogFilePath()
    {
        $plugin = $this->container->get('kernel')->getPlugins()['LuigisboxSearchSuiteShopware5'];
        return $plugin->getPath() . ' / ' . self::LOGFILE;
    }

    private function writeLog($msg)
    {
        Shopware()->Container()->get('pluginlogger')->info($msg);
    }
}
