<?php
/**
 * SEOmatic plugin for Craft CMS 3.x
 *
 * A turnkey SEO implementation for Craft CMS that is comprehensive, powerful,
 * and flexible
 *
 * @link      https://nystudio107.com
 * @copyright Copyright (c) 2017 nystudio107
 */

namespace nystudio107\seomatic\services;

use nystudio107\seomatic\Seomatic;
use nystudio107\seomatic\base\MetaContainer;
use nystudio107\seomatic\base\MetaItem;
use nystudio107\seomatic\models\jsonld\BreadcrumbList;
use nystudio107\seomatic\models\MetaBundle;
use nystudio107\seomatic\models\MetaGlobalVars;
use nystudio107\seomatic\models\MetaJsonLd;
use nystudio107\seomatic\models\MetaLink;
use nystudio107\seomatic\models\MetaScript;
use nystudio107\seomatic\models\MetaTag;
use nystudio107\seomatic\models\MetaTitle;
use nystudio107\seomatic\models\MetaJsonLdContainer;
use nystudio107\seomatic\models\MetaLinkContainer;
use nystudio107\seomatic\models\MetaScriptContainer;
use nystudio107\seomatic\models\MetaTagContainer;
use nystudio107\seomatic\models\MetaTitleContainer;

use Craft;
use craft\base\Component;
use craft\base\Element;
use craft\helpers\UrlHelper;
use craft\web\View;

use yii\base\Event;
use yii\base\Exception;
use yii\caching\TagDependency;

/**
 * @author    nystudio107
 * @package   Seomatic
 * @since     3.0.0
 */
class MetaContainers extends Component
{
    // Constants
    // =========================================================================

    const SEOMATIC_METATAG_CONTAINER = Seomatic::SEOMATIC_HANDLE . MetaTag::ITEM_TYPE;
    const SEOMATIC_METALINK_CONTAINER = Seomatic::SEOMATIC_HANDLE . MetaLink::ITEM_TYPE;
    const SEOMATIC_METASCRIPT_CONTAINER = Seomatic::SEOMATIC_HANDLE . MetaScript::ITEM_TYPE;
    const SEOMATIC_METAJSONLD_CONTAINER = Seomatic::SEOMATIC_HANDLE . MetaJsonLd::ITEM_TYPE;
    const SEOMATIC_METATITLE_CONTAINER = Seomatic::SEOMATIC_HANDLE . MetaTitle::ITEM_TYPE;

    const METATAG_GENERAL_HANDLE = 'general';
    const METATAG_STANDARD_HANDLE = 'standard';
    const METATAG_OPENGRAPH_HANDLE = 'opengraph';
    const METATAG_TWITTER_HANDLE = 'twitter';
    const METATAG_MISCELLANEOUS_HANDLE = 'misc';

    const METALINK_GENERAL_HANDLE = 'general';
    const METASCRIPT_GENERAL_HANDLE = 'general';
    const METAJSONLD_GENERAL_HANDLE = 'general';
    const METATITLE_GENERAL_HANDLE = 'general';

    const GLOBAL_METACONTAINER_CACHE_TAG = 'seomatic_metacontainer';
    const METACONTAINER_CACHE_TAG = 'seomatic_metacontainer_';

    const METACONTAINER_CACHE_DURATION = null;
    const DEVMODE_METACONTAINER_CACHE_DURATION = 30;
    const CACHE_KEY = 'seomatic_metacontainer_';

    // Public Properties
    // =========================================================================

    /**
     * @var MetaGlobalVars
     */
    public $metaGlobalVars;

    // Protected Properties
    // =========================================================================

    /**
     * @var MetaContainer
     */
    protected $metaContainers = [];

    /**
     * @var bool
     */
    protected $loadingContainers = false;

    /**
     * @var null|MetaBundle
     */
    protected $matchedMetaBundle = null;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();
    }

    /**
     * Load the meta containers
     *
     * @param string|null $path
     * @param int|null    $siteId
     */
    public function loadMetaContainers(string $path = '', int $siteId = null): void
    {
        // Avoid recursion
        if (empty($this->metaContainers && !$this->loadingContainers)) {
            $this->loadingContainers = true;
            $this->setMatchedElement($path, $siteId);
            // Get the cache tag for the matched meta bundle
            $metaBundle = $this->getMatchedMetaBundle();
            $metaBundleSourceId = "";
            if ($metaBundle) {
                $metaBundleSourceId = $metaBundle->sourceId;
            }
            // Load the meta containers
            $duration = Seomatic::$devMode
                ? $this::DEVMODE_METACONTAINER_CACHE_DURATION
                : $this::METACONTAINER_CACHE_DURATION;
            $dependency = new TagDependency([
                'tags' => [
                    $this::GLOBAL_METACONTAINER_CACHE_TAG,
                    $this::METACONTAINER_CACHE_TAG . $metaBundleSourceId,
                    $this::METACONTAINER_CACHE_TAG . $path . $siteId,
                ],
            ]);
            $cache = Craft::$app->getCache();
            list($this->metaGlobalVars, $this->metaContainers) = $cache->getOrSet(
                $this::CACHE_KEY . $path . $siteId,
                function () use ($path, $siteId) {
                    Craft::info(
                        'Meta container cache miss: ' . $path . '/' . $siteId,
                        'seomatic'
                    );
                    $this->loadGlobalMetaContainers($siteId);
                    $this->loadContentMetaContainers();
                    $this->addMetaJsonLdBreadCrumbs($siteId);
                    $this->addMetaLinkHrefLang();

                    return [$this->metaGlobalVars, $this->metaContainers];
                },
                $duration,
                $dependency
            );

            // Handler: View::EVENT_END_PAGE
            Event::on(
                View::className(),
                View::EVENT_END_PAGE,
                function (Event $event) {
                    Craft::trace(
                        'View::EVENT_END_PAGE',
                        'seomatic'
                    );
                    // The page is done rendering, include our meta containers
                    $this->includeMetaContainers();
                }
            );
            $this->loadingContainers = false;
        }
    }

    /**
     * Include the meta containers
     */
    public function includeMetaContainers(): void
    {
        foreach ($this->metaContainers as $metaContainer) {
            /** @var $metaContainer MetaContainer */
            if ($metaContainer->include) {
                $metaContainer->includeMetaData();
            }
        }
    }

    /**
     * @param string $title
     */
    public function includeMetaTitle(string $title)
    {
        $metaTitle = MetaTitle::create([
            'title' => $title,
        ]);
        $key = self::SEOMATIC_METATITLE_CONTAINER . self::METATITLE_GENERAL_HANDLE;
        $this->addToMetaContainer($metaTitle, $key);
    }

    /**
     * Add the passed in MetaItem to the MetaContainer indexed as $key
     *
     * @param $data MetaItem The MetaItem to add to the container
     * @param $key  string   The key to the container to add the data to
     *
     * @throws Exception     If the container $key doesn't exist
     */
    public function addToMetaContainer(MetaItem $data, string $key = null)
    {
        if (!$key || empty($this->metaContainers[$key])) {
            $error = Craft::t(
                'seomatic',
                'Meta container with key `{key}` does not exist.',
                ['key' => $key]
            );
            Craft::error($error, __METHOD__);
            throw new Exception($error);
        }
        /** @var  $container MetaContainer */
        $container = $this->metaContainers[$key];
        // If $uniqueKeys is set, generate a hash of the data for the key
        $dataKey = $data->key;
        if ($data->uniqueKeys) {
            $dataKey = $this->getHash($data);
        }
        $container->addData($data, $dataKey);
    }

    /**
     * Create a MetaContainer of the given $type with the $key
     *
     * @param string $type
     * @param string $key
     *
     * @return MetaContainer
     */
    public function createMetaContainer(string $type, string $key): MetaContainer
    {
        /** @var  $container MetaContainer */
        $container = null;
        if (empty($this->metaContainers[$key])) {
            /** @var  $className MetaContainer */
            $className = null;
            // Create a new container based on the type passed in
            switch ($type) {
                case MetaTagContainer::CONTAINER_TYPE:
                    $className = MetaTagContainer::className();
                    break;
                case MetaLinkContainer::CONTAINER_TYPE:
                    $className = MetaLinkContainer::className();
                    break;
                case MetaScriptContainer::CONTAINER_TYPE:
                    $className = MetaScriptContainer::className();
                    break;
                case MetaJsonLdContainer::CONTAINER_TYPE:
                    $className = MetaJsonLdContainer::className();
                    break;
                case MetaTitleContainer::CONTAINER_TYPE:
                    $className = MetaTitleContainer::className();
                    break;
            }
            if ($className) {
                $container = $className::create();
                if ($container) {
                    $this->metaContainers[$key] = $container;
                }
            }
        }

        return $container;
    }

    /**
     * Render the HTML of all MetaContainers of a specific $type
     *
     * @param string $type
     *
     * @return string
     */
    public function renderContainersByType(string $type): string
    {
        $html = '';
        /** @var  $metaContainer MetaContainer */
        foreach ($this->metaContainers as $metaContainer) {
            if ($metaContainer::CONTAINER_TYPE == $type) {
                $html .= $metaContainer->render();
            }
        }

        return $html;
    }

    /**
     * Render the HTML of all MetaContainers of a specific $type as an array
     *
     * @param string $type
     *
     * @return array
     */
    public function renderContainersArrayByType(string $type): array
    {
        $htmlArray = [];
        /** @var  $metaContainer MetaContainer */
        foreach ($this->metaContainers as $metaContainer) {
            if ($metaContainer::CONTAINER_TYPE == $type) {
                $htmlArray = array_merge($htmlArray, $metaContainer->renderArray());
            }
        }

        return $htmlArray;
    }


    // Protected Methods
    // =========================================================================

    /**
     * Load the global site meta containers
     *
     * @param int|null $siteId
     */
    protected function loadGlobalMetaContainers(int $siteId = null)
    {
        if (!$siteId) {
            $siteId = Craft::$app->getSites()->primarySite->id;
        }
        $metaBundle = Seomatic::$plugin->metaBundles->getGlobalMetaBundle($siteId);
        if ($metaBundle) {
            // Meta global vars
            $this->metaGlobalVars = $metaBundle->metaGlobalVars;
            $request = Craft::$app->getRequest();
            $this->metaGlobalVars->canonicalUrl = UrlHelper::siteUrl($request->getPathInfo(), null, null, $siteId);
            // Meta containers
            foreach ($metaBundle->metaTagContainer as $metaTagContainer) {
                $key = self::SEOMATIC_METATAG_CONTAINER . $metaTagContainer->handle;
                $this->metaContainers[$key] = $metaTagContainer;
            }
            foreach ($metaBundle->metaLinkContainer as $metaLinkContainer) {
                $key = self::SEOMATIC_METALINK_CONTAINER . $metaLinkContainer->handle;
                $this->metaContainers[$key] = $metaLinkContainer;
            }
            foreach ($metaBundle->metaScriptContainer as $metaScriptContainer) {
                $key = self::SEOMATIC_METASCRIPT_CONTAINER . $metaScriptContainer->handle;
                $this->metaContainers[$key] = $metaScriptContainer;
            }
            foreach ($metaBundle->metaJsonLdContainer as $metaJsonLdContainer) {
                $key = self::SEOMATIC_METAJSONLD_CONTAINER . $metaJsonLdContainer->handle;
                $this->metaContainers[$key] = $metaJsonLdContainer;
            }
            foreach ($metaBundle->metaTitleContainer as $metaTitleContainer) {
                $key = self::SEOMATIC_METATITLE_CONTAINER . $metaTitleContainer->handle;
                $this->metaContainers[$key] = $metaTitleContainer;
            }
        }
    }

    /**
     * Load the meta containers specific to the matched meta bundle
     */
    protected function loadContentMetaContainers()
    {
        $metaBundle = $this->getMatchedMetaBundle();
        if ($metaBundle) {
            $this->addMetaBundleToContainers($metaBundle);
        }
    }

    /**
     * Return the MetaBundle that corresponds with the Seomatic::$matchedElement
     *
     * @return null|MetaBundle
     */
    public function getMatchedMetaBundle()
    {
        if ($this->matchedMetaBundle) {
            return $this->matchedMetaBundle;
        }
        $metaBundle = null;
        /** @var Element $element */
        $element = Seomatic::$matchedElement;
        if ($element) {
            list($sourceId, $sourceSiteId) = Seomatic::$plugin->metaBundles->getMetaSourceIdFromElement($element);
            $metaBundle = Seomatic::$plugin->metaBundles->getMetaBundleBySourceId(
                $sourceId,
                $sourceSiteId
            );
        }
        $this->matchedMetaBundle = $metaBundle;

        return $metaBundle;
    }

    /**
     * Set the element that matches the URI in $path
     *
     * @param string   $path
     * @param int|null $siteId
     */
    protected function setMatchedElement(string $path, int $siteId = null)
    {
        if (!$siteId) {
            $siteId = Craft::$app->getSites()->primarySite->id;
        }
        /** @var Element $element */
        $element = Craft::$app->getElements()->getElementByUri($path, $siteId, true);
        if ($element) {
            Seomatic::setMatchedElement($element);
        }
    }

    /**
     * Add the meta bundle to our existing meta containers, overwriting meta
     * items with the same key
     *
     * @param MetaBundle $metaBundle
     */
    public function addMetaBundleToContainers(MetaBundle $metaBundle)
    {
        // Meta global vars
        $attributes = $metaBundle->getAttributes();
        $attributes = array_filter($attributes);
        $this->metaGlobalVars->setAttributes($attributes);
        /** @var  $element Element */
        $element = Seomatic::$matchedElement;
        $uri = $element->uri == '__home__' ? '' : $element->uri;
        $this->metaGlobalVars->canonicalUrl = UrlHelper::siteUrl(
            $uri,
            null,
            null,
            $metaBundle->sourceSiteId
        );
        // Meta containers
        foreach ($metaBundle->metaTagContainer as $metaTagContainer) {
            $key = self::SEOMATIC_METATAG_CONTAINER . $metaTagContainer->handle;
            foreach ($metaTagContainer->data as $metaTag) {
                $this->addToMetaContainer($metaTag, $key);
            }
        }
        foreach ($metaBundle->metaLinkContainer as $metaLinkContainer) {
            $key = self::SEOMATIC_METALINK_CONTAINER . $metaLinkContainer->handle;
            foreach ($metaLinkContainer->data as $metaLink) {
                $this->addToMetaContainer($metaLink, $key);
            }
        }
        foreach ($metaBundle->metaScriptContainer as $metaScriptContainer) {
            $key = self::SEOMATIC_METASCRIPT_CONTAINER . $metaScriptContainer->handle;
            foreach ($metaScriptContainer->data as $metaScript) {
                $this->addToMetaContainer($metaScript, $key);
            }
        }
        foreach ($metaBundle->metaJsonLdContainer as $metaJsonLdContainer) {
            $key = self::SEOMATIC_METAJSONLD_CONTAINER . $metaJsonLdContainer->handle;
            foreach ($metaJsonLdContainer->data as $metaJsonLd) {
                $this->addToMetaContainer($metaJsonLd, $key);
            }
        }
        foreach ($metaBundle->metaTitleContainer as $metaTitleContainer) {
            $key = self::SEOMATIC_METATITLE_CONTAINER . $metaTitleContainer->handle;
            foreach ($metaTitleContainer->data as $metaTitle) {
                $this->addToMetaContainer($metaTitle, $key);
            }
        }
    }

    /**
     * Invalidate all of the meta container caches
     */
    public function invalidateCaches()
    {
        $cache = Craft::$app->getCache();
        TagDependency::invalidate($cache, $this::GLOBAL_METACONTAINER_CACHE_TAG);
        Craft::info(
            'All meta container caches cleared',
            'seomatic'
        );
    }

    /**
     * Invalidate a meta bundle cache
     *
     * @param int $sourceId
     */
    public function invalidateContainerCacheById(int $sourceId)
    {
        $metaBundleSourceId = "";
        if ($sourceId) {
            $metaBundleSourceId = $sourceId;
        }
        $cache = Craft::$app->getCache();
        TagDependency::invalidate($cache, $this::METACONTAINER_CACHE_TAG . $metaBundleSourceId);
        Craft::info(
            'Meta bundle cache cleared: ' . $metaBundleSourceId,
            'seomatic'
        );
    }

    /**
     * Invalidate a meta bundle cache
     *
     * @param string $path
     * @param int    $siteId
     */
    public function invalidateContainerCacheByPath(string $path, int $siteId)
    {
        $cache = Craft::$app->getCache();
        TagDependency::invalidate($cache, $this::METACONTAINER_CACHE_TAG . $path . $siteId);
        Craft::info(
            'Meta container cache cleared: ' . $path . '/' . $siteId,
            'seomatic'
        );
    }

    // Protected Methods
    // =========================================================================

    /**
     * Add breadcrumbs to the MetaJsonLdContainer
     *
     * @param int|null $siteId
     */
    protected function addMetaJsonLdBreadCrumbs(int $siteId = null)
    {
        $position = 1;
        if (!$siteId) {
            $siteId = Craft::$app->getSites()->primarySite->id;
        }
        $site = Craft::$app->getSites()->getSiteById($siteId);
        $siteUrl = $site->hasUrls ? $site->baseUrl : Craft::$app->getSites()->getPrimarySite()->baseUrl;
        /** @var  $crumbs BreadcrumbList */
        $crumbs = MetaJsonLd::create("BreadCrumbList");
        /** @var  $element Element */
        $element = Craft::$app->getElements()->getElementByUri("__home__", $siteId);
        if ($element) {
            $uri = $element->uri == '__home__' ? '' : $element->uri;
            $listItem = MetaJsonLd::create("ListItem", [
                'position' => $position,
                'item'     => [
                    '@id'  => UrlHelper::siteUrl($uri, null, null, $siteId),
                    'name' => $element->title,
                ],
            ]);
            $crumbs->itemListElement[] = $listItem;
        } else {
            $crumbs->itemListElement[] = MetaJsonLd::create("ListItem", [
                'position' => $position,
                'item'     => [
                    '@id'  => $siteUrl,
                    'name' => 'Homepage',
                ],
            ]);
        }
        // Build up the segments, and look for elements that match
        $uri = '';
        $segments = Craft::$app->getRequest()->getSegments();
        /** @var  $lastElement Element */
        $lastElement = Seomatic::$matchedElement;
        if ($lastElement && $element) {
            if ($lastElement->uri != "__home__" && $element->uri) {
                $path = parse_url($lastElement->url, PHP_URL_PATH);
                $path = trim($path, "/");
                $segments = explode("/", $path);
            }
        }
        // Parse through the segments looking for elements that match
        foreach ($segments as $segment) {
            $uri .= $segment;
            $element = Craft::$app->getElements()->getElementByUri($uri, $siteId);
            if ($element && $element->uri) {
                $position++;
                $uri = $element->uri == '__home__' ? '' : $element->uri;
                $crumbs->itemListElement[] = MetaJsonLd::create("ListItem", [
                    'position' => $position,
                    'item'     => [
                        '@id'  => UrlHelper::siteUrl($uri, null, null, $siteId),
                        'name' => $element->title,
                    ],
                ]);
            }
            $uri .= "/";
        }

        $key = self::SEOMATIC_METAJSONLD_CONTAINER . self::METAJSONLD_GENERAL_HANDLE;
        $this->addToMetaContainer($crumbs, $key);
    }

    /**
     * Add meta hreflang tags if there is more than one site
     */
    protected function addMetaLinkHrefLang()
    {
        $sites = Craft::$app->getSites()->getAllSites();
        if (count($sites) > 1) {
            foreach ($sites as $site) {
                $siteUrl = $site->hasUrls ? $site->baseUrl : Craft::$app->getSites()->getPrimarySite()->baseUrl;
                $language = $site->language;
                $language = strtolower($language);
                $language = str_replace('_', '-', $language);
                $metaTag = MetaLink::create([
                    'rel'      => 'alternate',
                    'hreflang' => $language,
                    'href'     => $siteUrl,
                ]);
                $key = self::SEOMATIC_METALINK_CONTAINER . self::METALINK_GENERAL_HANDLE;
                $this->addToMetaContainer($metaTag, $key);
            }
        }
    }

    /**
     * Generate an md5 hash from an object or array
     *
     * @param MetaItem $data
     *
     * @return string
     */
    protected function getHash(MetaItem $data): string
    {
        if (is_object($data)) {
            $data = $data->toArray();
        }
        if (is_array($data)) {
            $data = serialize($data);
        }

        return md5($data);
    }

    // Private Methods
    // =========================================================================
}
