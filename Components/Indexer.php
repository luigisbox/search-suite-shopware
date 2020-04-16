<?php

namespace LuigisboxSearchSuite\Components;

use GuzzleHttp\Client;
use GuzzleHttp\Message\Request;
use GuzzleHttp\Stream\Stream;
use Shopware\Components\Routing\Context;
use Shopware\Models\Article\Article;
use LuigisboxSearchSuite\Models\Helper as H;
use Shopware\Models\Shop\Shop;

class Indexer
{
    const SCRIPT_URL_KEY = 'luigisBoxScriptUrl';
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

    const TIMEOUT = 300;
    const CHUNK_SIZE = 100;
    const TIMEOUT_CODES = [408, 504];
    const COMMIT_RATIO = 0.9;

    public $filePath = null;
    private $success = false;
    private $logEnabled = false;

    public function setFilePath($filePath)
    {
        $this->filePath = $filePath;
    }

    public function setLogEnabled($logEnabled)
    {
        $this->logEnabled = $logEnabled;
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

    public function getLogFilePath()
    {
        return $this->filePath;
    }

    public function singleContentUpdate($id)
    {
        $data = $this->getArticleData($id);

        if (count($data) === 0) {
            $this->writeLog("Luigi's Box product not found " . $id);
            return;
        }

        if ($data[0]['enabled']) {
            $this->contentRequest($data, self::TYPE_ITEM);
        } else {
            $this->contentDeleteRequest($data, self::TYPE_ITEM);
        }
    }


    private function getArticleData($id = null)
    {
        $mediaService = Shopware()->Container()->get('shopware_media.media_service');
        $shopContext = $this->getShopContext();
        $shopRouter = $this->getArticleRouter($shopContext);

        $properties = $this->getPropertiesOptions();
        $attributes = $this->getAttributeOptions();
        $productIDs = $this->getProductIds();
        $categories = $this->getCategories($shopRouter);

        $data = [];

        $this->writeLog("Loading article data started");

        if (!is_null($id)) {
            $product = $this->getApiOne($id);
            $data[] = $this->processData($product, $mediaService, $properties, $attributes, $categories, $shopRouter);

            return $data;
        }

        foreach ($productIDs as $productID) {
            $product = $this->getApiOne($productID->getId());

            if (!$product) {
                continue;
            }

            $data[] = $this->processData($product, $mediaService, $properties, $attributes, $categories, $shopRouter);
        }
        return $data;
    }

    private function processData($product, $mediaService, $properties, $attributes, $categories, $router)
    {
        $data = [
            'url' => $this->getArticleFrontendUrl($product['id'], $router),
            'type' => self::TYPE_ITEM,
            'fields' => [
                'title' => $product['name'],
                'availability' => $product['mainDetail']['active'] && $product['mainDetail']['inStock'] > 0 ? 1 : 0,
            ],
            'enabled' => $product['mainDetail']['active'],
            'nested' => [],
        ];

        if ($product['mainDetail']['number']) {
            $data['product_code'] = $product['mainDetail']['number'];
        }
        if ($product['mainDetail']['prices']['price']) {
            $data['price'] = $product['mainDetail']['prices']['price'];
        }
        if ($product['descriptionLong']) {
            $data['description'] = $product['descriptionLong'];
        }
        if ($product['description']) {
            $data['short_description'] = $product['description'];
        }


        $product['tax'] ? $data['fields']['tax'] = $product['tax']['name'] : null;

        foreach ($product['mainDetail'] as $key => $value) {
            if (!in_array($key, $this->getExcludedDetails()) && !$this->is_blank($value)) {
                $data['fields'][$key] = $value;
            }
        }

        foreach ($product['propertyValues'] as $propertyValue) {
            $propName = $properties[$propertyValue['optionId']];

            if (!$this->is_blank($propertyValue['value'] && !empty($propName))) {
                $data['fields'][$propName] = $propertyValue['value'];
            }
        }

        foreach ($product['mainDetail']['attribute'] as $key => $value) {
            if (!empty($value) && !empty($attributes[$key])) {
                $data['fields'][$attributes[$key]] = $value;
            }
        }

        if (!empty($product['images'])) {
            $image = $this->getMainImage($product['images']);
            $mediaUrl = $mediaService->getUrl('media / image / ' . $image['path'] . ' . ' . $image['extension']);
            if (!empty($mediaUrl)) {
                $data['image_link'] = $mediaUrl;
            }
        }

        foreach ($product['categories'] as $category) {
            if (!empty($categories[$category['id']])) {
                $data['nested'][] = $categories[$category['id']];
            }
        }

        return $data;
    }

    private function isStartingWith($query, $string)
    {
        return substr($string, 0, strlen($query)) === $query;
    }

    private function getProductRepository()
    {
        return Shopware()->Models()->getRepository(Article::class);
    }

    private function getProductIds()
    {
        $repo = $this->getProductRepository();
        return $repo->getArticlesWithExcludedIdsQuery()->execute();
    }

    private function getApiOne($id)
    {
        $resource = \Shopware\Components\Api\Manager::getResource('article');
        return $resource->getOne($id);
    }

    private function getMainImage($images)
    {
        $main = null;

        foreach ($images as $image) {
            if ($main == null || $image['main'] < $main['main']) {
                $main = $image;
            }
        }

        return $main;
    }

    private function getExcludedDetails()
    {
        return [
            'id', 'articleId', 'unitId', 'number', 'supplierNumber', 'kind', 'additionalText', 'active', 'inStock', 'stockMin', 'lastStock', 'position', 'minPurchase',
            'purchaseSteps', 'maxPurchase', 'purchaseUnit', 'referenceUnit', 'shippingFree', 'releaseDate', 'shippingTime', 'prices', 'attribute', 'configuratorOptions',
        ];
    }

    private function getPropertiesOptions()
    {
        $queryBuilder = Shopware()->Container()->get('dbal_connection')->createQueryBuilder();
        $queryBuilder->select(' * ')
            ->from('s_filter_options');

        $data = $queryBuilder->execute()->fetchAll();

        $options = [];

        foreach ($data as $row) {
            $options[$row['id']] = str_replace(' ', '_', strtolower($row['name']));
        }

        return $options;
    }

    private function getAttributeOptions()
    {
        $queryBuilder = Shopware()->Container()->get('dbal_connection')->createQueryBuilder();
        $queryBuilder->select(' * ')
            ->from('s_attribute_configuration');

        $data = $queryBuilder->execute()->fetchAll();

        $options = [];

        foreach ($data as $row) {
            $options[$row['column_name']] = str_replace(' ', '_', strtolower($row['label']));
        }

        return $options;
    }

    private function getShopUrl()
    {
        $myShop = Shopware()->Models()->getRepository(Shop::class)->getMainListQuery()->execute()[0];

        return $myShop->getHost() . $myShop->getBasePath();
    }

    private function getShopContext()
    {
        $shop = Shopware()->Models()->getRepository(Shop::class)->getMainListQuery()->execute()[0];

        $config = clone Shopware()->Config();
        $config->setShop($shop);

        $context = Context::createFromShop($shop, $config);
        $context->setBaseUrl($shop->getBasePath());

        return $context;
    }

    private function getArticleRouter($context)
    {
        $router = clone Shopware()->Container()->get('router');

        $router->setContext($context);

        return $router;
    }

    private function getArticleFrontendUrl($id, $router)
    {
        $url = $router->assemble([
            'module' => 'frontend',
            'controller' => 'detail',
            'sArticle' => $id
        ]);

        return $url;
    }

    private function getCategoryFrontendUrlFromRouter($id, $router)
    {
        $url = $router->assemble([
            'module' => 'frontend',
            'controller' => 'category',
            'sCategory' => $id
        ]);

        return $url;
    }

    private function getCategoryFrontendUrl($id, $router)
    {
        $url = $this->getCategoryFrontendUrlFromRouter($id, $router);

        // if is SEO url than return url else get url from the database
        if (!strpos($url, 'sCategory')) {
            return $url;
        }

        $queryBuilder = Shopware()->Container()->get('dbal_connection')->createQueryBuilder();
        $queryBuilder->select(' * ')
            ->from('s_core_rewrite_urls')
            ->where('org_path = :path')
            ->setParameter(':path', 'sViewport = cat & sCategory = ' . $id);

        $data = $queryBuilder->execute()->fetch();

        if ($this->isStartingWith('https', $url)) {
            $protocol = 'https://';
        } else {
            $protocol = 'http://';
        }

        return $protocol . $this->getShopUrl() . '/' . $data['path'];
    }

    private function getCategoriesListApi()
    {
        $resource = \Shopware\Components\Api\Manager::getResource('category');
        return $resource->getList();
    }

    private function getCategoryApiOne($id)
    {
        $resource = \Shopware\Components\Api\Manager::getResource('category');
        $category = $resource->getOne($id);

        return $category;
    }

    private function getCategoryAncestors($parentId, $categories, $router)
    {
        if (!$parentId) {
            return [];
        }

        $ancestors = [];
        while ($parentId) {
            foreach ($categories as $category) {
                if ($category['id'] == $parentId) {
                    $ancestors[] = [
                        'title' => $category['name'],
                        'url' => $this->getCategoryFrontendUrl($category['id'], $router),
                    ];

                    $parentId = $category['parentId'];
                    break;
                }
            }
        }

        return $ancestors;
    }

    private function getCategories($router)
    {
        $categoriesCollection = $this->getCategoriesListApi()['data'];
        $categories = [];

        foreach ($categoriesCollection as $categoryCollection) {
            $categories[$categoryCollection['id']] = [
                'type' => self::TYPE_CATEGORY,
                'url' => $this->getCategoryFrontendUrl($categoryCollection['id'], $router),
                'fields' => [
                    'title' => $categoryCollection['name'],
                    'ancestors' => $this->getCategoryAncestors($categoryCollection['parentId'], $categoriesCollection, $router)
                ]
            ];
        }

        return $categories;
    }

    private function is_blank($value)
    {
        return empty($value) && !is_numeric($value);
    }

    // --------------------------------
    // |                              |
    // |   METHODS TO SET UP REQUEST  |
    // |                              |
    // --------------------------------

    public function appendTypeAndGenerationToData($data, $type, $generation)
    {
        foreach ($data as $key => $datum) {
            $data[$key]['type'] = $type;
            $data[$key]['generation'] = $generation;
        }

        return $data;
    }

    public function appendTypeToData($data, $type)
    {
        foreach ($data as $key => $datum) {
            $data[$key]['type'] = $type;
        }

        return $data;
    }

    private function getRequest($endpoint)
    {
        $request = new Request(
            $this->getMethodForEndpoint($endpoint),
            $this->getUriForEndpoint($endpoint),
            $this->getHeaders($endpoint)
        );

        return $request;
    }

    public function getContentRequest($data)
    {
        $request = new Request(
            $this->getMethodForEndpoint(self::API_CONTENT_ENDPOINT),
            $this->getUriForEndpoint(self::API_CONTENT_ENDPOINT),
            $this->getHeaders(self::API_CONTENT_ENDPOINT),
            Stream::factory(json_encode(['objects' => $data])),
            ['timeout' => 30]
        );

        return $request;
    }

    public function getContentDeleteRequest($data)
    {
        $request = new Request(
            $this->getMethodForEndpoint(self::API_CONTENT_DELETE_ENDPOINT),
            $this->getUriForEndpoint(self::API_CONTENT_DELETE_ENDPOINT),
            $this->getHeaders(self::API_CONTENT_DELETE_ENDPOINT),
            Stream::factory(json_encode(['objects' => $data])),
            ['timeout' => 30]
        );
        return $request;
    }

    public function getCommitRequest($generation, $type)
    {
        $request = $this->getRequest(self::API_COMMIT_ENDPOINT);

        $params = [
            'generation' => $generation,
            'type' => $type
        ];

        $request->setQuery(http_build_query($params));

        return $request;
    }

    public function getMethodForEndpoint($endpoint)
    {
        $methods = [
            self::API_CONTENT_ENDPOINT => "POST",
            self::API_CONTENT_DELETE_ENDPOINT => "DELETE",
            self::API_COMMIT_ENDPOINT => "POST",
        ];

        if (array_key_exists($endpoint, $methods)) {
            return $methods[$endpoint];
        }

        return "POST";
    }


    private function getHeaders($endpoint)
    {
        $date = gmdate('D, d M Y H:i:s T');
        $method = $this->getMethodForEndpoint($endpoint);

        $signature = $this->digest($this->getApiKey(), $method, parse_url($this->getApiUri($endpoint), PHP_URL_PATH), $date);

        $httpHeaders = [
            'Content-Type' => 'application/json',
            'date' => $date,
            'Authorization' => "shopware {$this->getTrackerId()}:{$signature}",
        ];

        return $httpHeaders;
    }

    public function digest($key, $method, $endpoint, $date)
    {
        $content_type = 'application/json';

        $data = "{$method}\n{$content_type}\n{$date}\n{$endpoint}";

        $signature = trim(base64_encode(hash_hmac('sha256', $data, $key, true)));

        return $signature;
    }

    public function getApiUri($endpoint)
    {
        $uris = [
            self::API_CONTENT_ENDPOINT => self::API_CONTENT_URI,
            self::API_CONTENT_DELETE_ENDPOINT => self::API_CONTENT_URI,
            self::API_COMMIT_ENDPOINT => self::API_CONTENT_COMMIT_URI,
        ];

        if (array_key_exists($endpoint, $uris)) {
            return $uris[$endpoint];
        }

        return null;
    }

    public function getUriForEndpoint($endpoint)
    {
        $uris = [
            self::API_CONTENT_ENDPOINT => self::API_CONTENT_URI,
            self::API_CONTENT_DELETE_ENDPOINT => self::API_CONTENT_URI,
            self::API_COMMIT_ENDPOINT => self::API_CONTENT_COMMIT_URI,
        ];

        if (array_key_exists($endpoint, $uris)) {
            return $uris[$endpoint];
        }

        return self::API_CONTENT_URI;
    }

    public function allContentUpdate()
    {
        $this->success = false;
        $this->writeLog("Luigi's Box product update start.");

        $data = $this->getArticleData();

        $generation = (string)round(microtime(true));

        $this->success = $this->contentRequest($data, self::TYPE_ITEM, $generation, [self::TYPE_CATEGORY]);

        $this->writeLog("Luigi's Box product update end.");

        return $this->success;
    }

    public function contentRequest($data, $type, $generation = null, $nestedTypes = [])
    {
        if ($generation === null) {
            $data = $this->appendTypeToData($data, $type);
        } else {
            $data = $this->appendTypeAndGenerationToData($data, $type, $generation);
        }

        $success = true;
        $chunks = 0;
        $validCount = 0;
        $invalidCount = 0;

        foreach (array_chunk($data, self::CHUNK_SIZE) as $chunk) {
            $this->writeLog("Started with chunk: " . ($chunks + 1));

            try {
                $client = new Client();

                $response = $client->post($this->getApiUri(self::API_CONTENT_ENDPOINT), [
                    'headers' => $this->getHeaders(self::API_CONTENT_ENDPOINT),
                    'body' => json_encode(['objects' => $chunk]),
                    'timeout' => self::TIMEOUT,
                ]);

                $validResponse = ($response->getStatusCode() === 201);

                if ($validResponse) {
                    $validCount += count($chunk);
                } else {
                    if (in_array($response->getStatusCode(), self::TIMEOUT_CODES)) {
                        // Try resend request when timeout occured
                        sleep(5);
                        $response = $client->post($this->getApiUri(self::API_CONTENT_ENDPOINT), [
                            'headers' => $this->getHeaders(self::API_CONTENT_ENDPOINT),
                            'body' => json_encode(['objects' => $chunk]),
                            'timeout' => self::TIMEOUT,
                        ]);;
                        $validResponse = ($response->getStatusCode() === 201);
                    }

                    if ($validResponse) {
                        $validCount += count($chunk);
                    } else {
                        if ($response->getStatusCode() < 500) {
                            if ($jsonResponse = json_decode($response->getBody())) {
                                $validCount += (isset($jsonResponse->ok_count) ? $jsonResponse->ok_count : 0);
                                $invalidCount += (isset($jsonResponse->errors_count) ? $jsonResponse->errors_count : count($chunk));
                            } else {
                                $invalidCount += count($chunk);
                            }
                            $this->writeLog('Luigi\'s Box: invalid content api response code ' . $response->getStatusCode() . ', response ' . $response->getBody());
                        }
                    }
                }

                if (!$validResponse) {
                    $this->writeLog('Luigi\'s Box: invalid response from content api');
                }

                $chunks += 1;
            } catch (\Exception $ex) {
                $this->writeLog($ex->getMessage());
                $this->writeLog("Error accessing Luigi's Box API.");
                $success = false;
                break;
            }
        }

        $validRatio = ($validCount > 0) ? ($validCount / ($validCount + $invalidCount)) : 0;

        if ($success && $chunks > 0 && $generation !== null && $validRatio >= self::COMMIT_RATIO) {
            $client = new Client();

            $types = array_merge([$type], $nestedTypes);
            foreach ($types as $commitType) {
                try {
                    $response = $client->post($this->getApiUri(self::API_COMMIT_ENDPOINT), [
                        'headers' => $this->getHeaders(self::API_COMMIT_ENDPOINT),
                        'timeout' => self::TIMEOUT,
                        'query' => [
                            'generation' => $generation,
                            'type' => $commitType
                        ],
                    ]);
                    if ($response->getStatusCode() != 201) {
                        $this->writeLog("Invalid response from Luigi's Box API.");
                        $success = false;
                        break;
                    }
                } catch (\Exception $ex) {
                    $this->writeLog("Error accessing Luigi's Box API.");
                    $success = false;
                    break;
                }
            }
        }

        if ($success && $validRatio >= self::COMMIT_RATIO) {
            $this->writeLog("Luigi's Box content generation {$generation} committed");
        }

        return $success && $validRatio >= self::COMMIT_RATIO;
    }

    public function contentDeleteRequest($data, $type)
    {
        $objects = [];
        foreach ($data as $datum) {
            $objects[] = [
                'type' => $type,
                'url' => $datum['url']
            ];
        }

        $request = $this->getContentDeleteRequest($objects);

        $success = true;
        $client = new Client();
        try {
            $response = $client->send($request);

            if ($response->getStatusCode() != 200) {
                $this->writeLog("Invalid response from Luigi's Box API.");
                $success = false;
            }
        } catch (\Exception $ex) {
            $this->writeLog("Error accessing Luigi's Box API.");
            $success = false;
        }

        return $success;
    }

    private function writeLog($msg)
    {
        //Shopware()->Container()->get('pluginlogger')->info($msg);
        if ($this->logEnabled) {
            echo $msg . "\n";
        }
    }
}
