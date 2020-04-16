<?php

namespace LuigisboxSearchSuite;

use Shopware\Components\Plugin;
use LuigisboxSearchSuite\Models\Helper as H;


class LuigisboxSearchSuite extends Plugin
{
    const SCRIPT_URL_KEY = 'luigisBoxScriptUrl';
    const SYNC_ENABLED_KEY = 'luigisBoxCatalogSynchronization';
    const API_KEY = 'luigisBoxApiKey';
    const TRACKER_ID_KEY = 'luigisBoxTrackerID';

    const LOGFILE = 'update.txt';

    private $indexer;

    public function install(Plugin\Context\InstallContext $context)
    {
        parent::install($context);

        H::setIndexInvalidationTimestamp($this->getLogFilePath());
    }

    public static function getSubscribedEvents()
    {
        return [
            'Enlight_Controller_Action_PreDispatch' => 'addScript',
            'Shopware_CronJob_SendToLuigisBoxApi' => 'sendProductsToLB',
            'Enlight_Controller_Action_PostDispatch_Backend_Article' => 'singleProductUpdate'
        ];
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
        return Shopware()->Config()->getByNamespace('LuigisBoxSearchSuite', self::SYNC_ENABLED_KEY);
    }

    public function getApiKey()
    {
        return Shopware()->Config()->getByNamespace('LuigisBoxSearchSuite', self::API_KEY);
    }

    public function getTrackerId()
    {
        return Shopware()->Config()->getByNamespace('LuigisBoxSearchSuite', self::TRACKER_ID_KEY);
    }

    public function getScriptUrl()
    {
        return Shopware()->Config()->getByNamespace('LuigisBoxSearchSuite', self::SCRIPT_URL_KEY);
    }

    public function getLogFilePath()
    {
        return $this->getPath() . '/' . self::LOGFILE;
    }

    public function addScript(\Enlight_Controller_ActionEventArgs $args)
    {
        $controller = $args->get('subject');
        $view = $controller->View();

        $view->addTemplateDir($this->getPath() . '/Resources/views');

        $scriptURL = $this->getScriptUrl();

        $view->assign('luigisBoxScriptUrl', $scriptURL);
    }

    public function singleProductUpdate(\Enlight_Event_EventArgs $args)
    {
        $request = $args->getRequest();

        if ($request->getActionName() !== 'save') {
            return;
        }

        $articleId = $request->getParam('id', null);

        if (!is_numeric($articleId) || !$this->isConfigured()) {
            return;
        }

        try {
            $filePath = $this->getLogFilePath();
            $this->indexer = $this->container->get('luigisbox_search_suite.indexer');
            $this->indexer->setFilePath($filePath);
            $this->indexer->singleContentUpdate($articleId);
        } catch (\Exception $exception) {
            return;
        }
    }


    public function sendProductsToLB(\Shopware_Components_Cron_CronJob $job)
    {
        $filePath = $this->getLogFilePath();

        if (!$this->isConfigured() || H::isIndexRunning($filePath)) {
            $this->writeLog('Prevent indexing.');
            return;
        }

        H::markIndexRunning($filePath);

        $this->indexer = $this->container->get('luigisbox_search_suite.indexer');

        $this->indexer->setFilePath($filePath);
        $this->indexer->allContentUpdate();

        H::markIndexFinished($filePath);
    }

    private function writeLog($msg)
    {
        Shopware()->Container()->get('pluginlogger')->info($msg);
        echo $msg . "\n";
    }
}
