<?php
/**
 * SEOmatic plugin for Craft CMS 3.x
 *
 * @link      https://nystudio107.com/
 * @copyright Copyright (c) 2017 nystudio107
 * @license   https://nystudio107.com/license
 */

namespace nystudio107\seomatic\controllers;

use nystudio107\seomatic\helpers\ArrayHelper;
use nystudio107\seomatic\models\MetaScriptContainer;
use nystudio107\seomatic\Seomatic;
use nystudio107\seomatic\assetbundles\seomatic\SeomaticAsset;
use nystudio107\seomatic\helpers\Field as FieldHelper;

use Craft;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\helpers\UrlHelper;
use craft\models\Site;
use craft\web\Controller;

use nystudio107\seomatic\services\FrontendTemplates;
use nystudio107\seomatic\services\MetaBundles;
use yii\base\InvalidConfigException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * @author    nystudio107
 * @package   Seomatic
 * @since     3.0.0
 */
class SettingsController extends Controller
{
    // Constants
    // =========================================================================

    const DOCUMENTATION_URL = 'https://github.com/nystudio107/craft-seomatic/wiki';

    const PULL_TEXT_FIELDS = [
        ['fieldName' => 'seoTitle', 'seoField' => 'seoTitle'],
        ['fieldName' => 'seoDescription', 'seoField' => 'seoDescription'],
        ['fieldName' => 'seoKeywords', 'seoField' => 'seoKeywords'],
        ['fieldName' => 'seoImageDescription', 'seoField' => 'seoImageDescription'],
        ['fieldName' => 'ogTitle', 'seoField' => 'seoTitle'],
        ['fieldName' => 'ogDescription', 'seoField' => 'seoDescription'],
        ['fieldName' => 'ogImageDescription', 'seoField' => 'seoImageDescription'],
        ['fieldName' => 'twitterTitle', 'seoField' => 'seoTitle'],
        ['fieldName' => 'twitterDescription', 'seoField' => 'seoDescription'],
        ['fieldName' => 'twitterImageDescription', 'seoField' => 'seoImageDescription'],
    ];

    const PULL_ASSET_FIELDS = [
        ['fieldName' => 'seoImage', 'seoField' => 'seoImage', 'transformName' => 'base'],
        ['fieldName' => 'ogImage', 'seoField' => 'seoImage', 'transformName' => 'facebook'],
        ['fieldName' => 'twitterImage', 'seoField' => 'seoImage', 'transformName' => 'twitter'],
    ];


    // Protected Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected $allowAnonymous = [
    ];

    // Public Methods
    // =========================================================================

    /**
     * Global settings
     *
     * @param string|null $siteHandle
     *
     * @return Response The rendered result
     * @throws NotFoundHttpException
     * @throws \yii\web\ForbiddenHttpException
     */
    public function actionGlobal(string $subSection = "general", string $siteHandle = null): Response
    {
        $variables = [];
        $siteId = $this->getSiteIdFromHandle($siteHandle);

        $pluginName = Seomatic::$settings->pluginName;
        $templateTitle = Craft::t('seomatic', 'Global SEO');
        $subSectionTitle = Craft::t('seomatic', ucfirst($subSection));
        // Asset bundle
        try {
            Seomatic::$view->registerAssetBundle(SeomaticAsset::class);
        } catch (InvalidConfigException $e) {
            Craft::error($e->getMessage(), __METHOD__);
        }
        $variables['baseAssetsUrl'] = Craft::$app->assetManager->getPublishedUrl(
            '@nystudio107/seomatic/assetbundles/seomatic/dist',
            true
        );
        // Basic variables
        $variables['fullPageForm'] = true;
        $variables['docsUrl'] = self::DOCUMENTATION_URL;
        $variables['pluginName'] = Seomatic::$settings->pluginName;
        $variables['title'] = $templateTitle;
        $variables['subSectionTitle'] = $subSectionTitle;
        $variables['docTitle'] = $templateTitle.' - '.$subSectionTitle;
        $variables['crumbs'] = [
            [
                'label' => $pluginName,
                'url'   => UrlHelper::cpUrl('seomatic'),
            ],
            [
                'label' => $templateTitle,
                'url'   => UrlHelper::cpUrl('seomatic/global'),
            ],
            [
                'label' => $subSectionTitle,
                'url'   => UrlHelper::cpUrl('seomatic/global/'.$subSection),
            ],
        ];
        $variables['selectedSubnavItem'] = 'global';
        // Pass in the pull fields
        $this->setGlobalFieldSourceVariables($variables);
        // Enabled sites
        $this->setMultiSiteVariables($siteHandle, $siteId, $variables);
        $variables['controllerHandle'] = 'global' . '/' . $subSection;
        $variables['currentSubSection'] = $subSection;
        // Meta bundle settings
        $metaBundle = Seomatic::$plugin->metaBundles->getGlobalMetaBundle(intval($variables['currentSiteId']));
        $variables['globals'] = $metaBundle->metaGlobalVars;
        $variables['sitemap'] = $metaBundle->metaSitemapVars;
        $variables['settings'] = $metaBundle->metaBundleSettings;
        // Template container settings
        $templateContainers = $metaBundle->frontendTemplatesContainer->data;
        $variables['robotsTemplate'] = $templateContainers[FrontendTemplates::ROBOTS_TXT_HANDLE];
        $variables['humansTemplate'] = $templateContainers[FrontendTemplates::HUMANS_TXT_HANDLE];
        // Image selectors
        $bundleSettings = $metaBundle->metaBundleSettings;
        $variables['elementType'] = Asset::class;
        $variables['seoImageElements'] = $this->assetElementsFromIds($bundleSettings->seoImageIds, $siteId);
        $variables['twitterImageElements'] = $this->assetElementsFromIds($bundleSettings->twitterImageIds, $siteId);
        $variables['ogImageElements'] = $this->assetElementsFromIds($bundleSettings->ogImageIds, $siteId);
        // Preview the meta containers
        Seomatic::$plugin->metaContainers->previewMetaContainers(
            MetaBundles::GLOBAL_META_BUNDLE,
            intval($variables['currentSiteId'])
        );

        // Render the template
        return $this->renderTemplate('seomatic/settings/global/'.$subSection, $variables);
    }

    /**
     * @return Response
     * @throws \yii\web\BadRequestHttpException
     */
    public function actionSaveGlobal()
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();
        $siteId = $request->getParam('siteId');
        $globalsSettings = $request->getParam('globals');
        $bundleSettings = $request->getParam('settings');
        $robotsTemplate = $request->getParam('robotsTemplate');
        $humansTemplate = $request->getParam('humansTemplate');

        // Set the element type in the template
        $elementName = '';

        // The site settings for the appropriate meta bundle
        $metaBundle = Seomatic::$plugin->metaBundles->getGlobalMetaBundle($siteId);
        if ($metaBundle) {
            if (is_array($globalsSettings) && is_array($bundleSettings)) {
                $this->parseTextSources($elementName, $globalsSettings, $bundleSettings);
                $this->parseImageSources($elementName, $globalsSettings, $bundleSettings, $siteId);
                $metaBundle->metaGlobalVars->setAttributes($globalsSettings);
                $metaBundle->metaBundleSettings->setAttributes($bundleSettings);
            }
            $templateContainers = $metaBundle->frontendTemplatesContainer->data;
            $robotsContainer = $templateContainers[FrontendTemplates::ROBOTS_TXT_HANDLE];
            if (!empty($robotsContainer) && is_array($robotsTemplate)) {
                $robotsContainer->setAttributes($robotsTemplate);
            }
            $humansContainer = $templateContainers[FrontendTemplates::HUMANS_TXT_HANDLE];
            if (!empty($humansContainer) && is_array($humansTemplate)) {
                $humansContainer->setAttributes($humansTemplate);
            }

            Seomatic::$plugin->metaBundles->updateMetaBundle($metaBundle, $siteId);

            Seomatic::$plugin->clearAllCaches();
            Craft::$app->getSession()->setNotice(Craft::t('seomatic', 'SEOmatic global settings saved.'));
        }

        return $this->redirectToPostedUrl();
    }

    /**
     * Content settings
     *
     * @param string|null $siteHandle
     *
     * @return Response The rendered result
     * @throws NotFoundHttpException
     * @throws \yii\web\ForbiddenHttpException
     */
    public function actionContent(string $siteHandle = null): Response
    {
        $variables = [];
        // Get the site to edit
        $siteId = $this->getSiteIdFromHandle($siteHandle);

        $pluginName = Seomatic::$settings->pluginName;
        $templateTitle = Craft::t('seomatic', 'Content SEO');
        // Asset bundle
        try {
            Seomatic::$view->registerAssetBundle(SeomaticAsset::class);
        } catch (InvalidConfigException $e) {
            Craft::error($e->getMessage(), __METHOD__);
        }
        $variables['baseAssetsUrl'] = Craft::$app->assetManager->getPublishedUrl(
            '@nystudio107/seomatic/assetbundles/seomatic/dist',
            true
        );
        // Basic variables
        $variables['fullPageForm'] = false;
        $variables['docsUrl'] = self::DOCUMENTATION_URL;
        $variables['pluginName'] = Seomatic::$settings->pluginName;
        $variables['title'] = $templateTitle;
        $variables['crumbs'] = [
            [
                'label' => $pluginName,
                'url'   => UrlHelper::cpUrl('seomatic'),
            ],
            [
                'label' => $templateTitle,
                'url'   => UrlHelper::cpUrl('seomatic/content'),
            ],
        ];
        $this->setMultiSiteVariables($siteHandle, $siteId, $variables);
        $variables['controllerHandle'] = 'content';
        $variables['selectedSubnavItem'] = 'content';
        $variables['metaBundles'] = Seomatic::$plugin->metaBundles->getContentMetaBundlesForSiteId($siteId);

        // Render the template
        return $this->renderTemplate('seomatic/settings/content/index', $variables);
    }

    /**
     * Global settings
     *
     * @param string      $sourceBundleType
     * @param string      $sourceHandle
     * @param string|null $siteHandle
     *
     * @return Response The rendered result
     * @throws NotFoundHttpException
     * @throws \yii\web\ForbiddenHttpException
     */
    public function actionEditContent(
        string $sourceBundleType,
        string $sourceHandle,
        string $siteHandle = null
    ): Response {
        $variables = [];
        // @TODO: Let people choose an entry/categorygroup/product as the preview
        // Get the site to edit
        $siteId = $this->getSiteIdFromHandle($siteHandle);

        $pluginName = Seomatic::$settings->pluginName;
        // Asset bundle
        try {
            Seomatic::$view->registerAssetBundle(SeomaticAsset::class);
        } catch (InvalidConfigException $e) {
            Craft::error($e->getMessage(), __METHOD__);
        }
        $variables['baseAssetsUrl'] = Craft::$app->assetManager->getPublishedUrl(
            '@nystudio107/seomatic/assetbundles/seomatic/dist',
            true
        );
        // Enabled sites
        $this->setMultiSiteVariables($siteHandle, $siteId, $variables);
        // Meta Bundle settings
        $metaBundle = Seomatic::$plugin->metaBundles->getMetaBundleBySourceHandle(
            $sourceBundleType,
            $sourceHandle,
            intval($variables['currentSiteId'])
        );
        $variables['globals'] = $metaBundle->metaGlobalVars;
        $variables['sitemap'] = $metaBundle->metaSitemapVars;
        $variables['settings'] = $metaBundle->metaBundleSettings;
        $variables['currentSourceHandle'] = $metaBundle->sourceHandle;
        $variables['currentSourceBundleType'] = $metaBundle->sourceBundleType;
        // Basic variables
        $templateTitle = $metaBundle->sourceName;
        $variables['fullPageForm'] = true;
        $variables['docsUrl'] = self::DOCUMENTATION_URL;
        $variables['pluginName'] = Seomatic::$settings->pluginName;
        $variables['title'] = $templateTitle;
        $variables['crumbs'] = [
            [
                'label' => $pluginName,
                'url'   => UrlHelper::cpUrl('seomatic'),
            ],
            [
                'label' => 'Content SEO',
                'url'   => UrlHelper::cpUrl('seomatic/content'),
            ],
            [
                'label' => $templateTitle,
                'url'   => UrlHelper::cpUrl('seomatic/content'),
            ],
        ];
        $variables['selectedSubnavItem'] = 'content';
        $variables['controllerHandle'] = "edit-content/${sourceBundleType}/${sourceHandle}";
        // Image selectors
        $bundleSettings = $metaBundle->metaBundleSettings;
        $variables['elementType'] = Asset::class;
        $variables['seoImageElements'] = $this->assetElementsFromIds($bundleSettings->seoImageIds, $siteId);
        $variables['twitterImageElements'] = $this->assetElementsFromIds($bundleSettings->twitterImageIds, $siteId);
        $variables['ogImageElements'] = $this->assetElementsFromIds($bundleSettings->ogImageIds, $siteId);
        // Pass in the pull fields
        $groupName = "Entry";
        $this->setContentFieldSourceVariables($sourceBundleType, $sourceHandle, $groupName, $variables);
        $uri = $this->uriFromSourceBundle($sourceBundleType, $sourceHandle);
        // Preview the meta containers
        Seomatic::$plugin->metaContainers->previewMetaContainers(
            $uri,
            intval($variables['currentSiteId'])
        );

        // Render the template
        return $this->renderTemplate('seomatic/settings/content/_edit', $variables);
    }


    /**
     * @return Response
     * @throws \yii\web\BadRequestHttpException
     */
    public function actionSaveContent()
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();
        $sourceBundleType = $request->getParam('sourceBundleType');
        $sourceHandle = $request->getParam('sourceHandle');
        $siteId = $request->getParam('siteId');
        $globalsSettings = $request->getParam('globals');
        $bundleSettings = $request->getParam('settings');
        $sitemapSettings = $request->getParam('sitemap');

        // Set the element type in the template
        switch ($sourceBundleType) {
            case MetaBundles::SECTION_META_BUNDLE:
                $elementName = 'entry';
                break;
            case MetaBundles::CATEGORYGROUP_META_BUNDLE:
                $elementName = 'category';
                break;
            default:
                $elementName = '';
                break;
        }
        // The site settings for the appropriate meta bundle
        $metaBundle = Seomatic::$plugin->metaBundles->getMetaBundleBySourceHandle(
            $sourceBundleType,
            $sourceHandle,
            $siteId
        );
        if ($metaBundle) {
            if (is_array($globalsSettings) && is_array($bundleSettings)) {
                $this->parseTextSources($elementName, $globalsSettings, $bundleSettings);
                $this->parseImageSources($elementName, $globalsSettings, $bundleSettings, $siteId);
                $metaBundle->metaGlobalVars->setAttributes($globalsSettings);
                $metaBundle->metaBundleSettings->setAttributes($bundleSettings);
            }
            if (is_array($sitemapSettings)) {
                $metaBundle->metaSitemapVars->setAttributes($sitemapSettings);
            }

            Seomatic::$plugin->metaBundles->updateMetaBundle($metaBundle, $siteId);

            Seomatic::$plugin->clearAllCaches();
            Craft::$app->getSession()->setNotice(Craft::t('seomatic', 'SEOmatic content settings saved.'));
        }

        return $this->redirectToPostedUrl();
    }

    /**
     * Site settings
     *
     * @param string $siteHandle
     *
     * @return Response The rendered result
     * @throws NotFoundHttpException
     * @throws \yii\web\ForbiddenHttpException
     */
    public function actionSite(string $siteHandle = null): Response
    {
        $variables = [];
        // Get the site to edit
        $siteId = $this->getSiteIdFromHandle($siteHandle);

        $pluginName = Seomatic::$settings->pluginName;
        $templateTitle = Craft::t('seomatic', 'Site Settings');
        // Asset bundle
        try {
            Seomatic::$view->registerAssetBundle(SeomaticAsset::class);
        } catch (InvalidConfigException $e) {
            Craft::error($e->getMessage(), __METHOD__);
        }
        $variables['baseAssetsUrl'] = Craft::$app->assetManager->getPublishedUrl(
            '@nystudio107/seomatic/assetbundles/seomatic/dist',
            true
        );
        // Basic variables
        $variables['fullPageForm'] = true;
        $variables['docsUrl'] = self::DOCUMENTATION_URL;
        $variables['pluginName'] = Seomatic::$settings->pluginName;
        $variables['title'] = $pluginName.' '.$templateTitle;
        $variables['crumbs'] = [
            [
                'label' => $pluginName,
                'url'   => UrlHelper::cpUrl('seomatic'),
            ],
            [
                'label' => $templateTitle,
                'url'   => UrlHelper::cpUrl('seomatic/site'),
            ],
        ];
        $variables['selectedSubnavItem'] = 'site';

        // Enabled sites
        $this->setMultiSiteVariables($siteHandle, $siteId, $variables);
        $variables['controllerHandle'] = 'site';

        // The site settings for the appropriate meta bundle
        $metaBundle = Seomatic::$plugin->metaBundles->getGlobalMetaBundle(intval($variables['currentSiteId']));
        $variables['site'] = $metaBundle->metaSiteVars;

        // Render the template
        return $this->renderTemplate('seomatic/settings/site/_edit', $variables);
    }

    /**
     * @return Response
     * @throws \yii\web\BadRequestHttpException
     */
    public function actionSaveSite()
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();
        $siteId = $request->getParam('siteId');
        $siteSettings = $request->getParam('site');

        // Make sure the twitter handle isn't prefixed with an @
        if (!empty($siteSettings['twitterHandle'])) {
            $siteSettings['twitterHandle'] = ltrim($siteSettings['twitterHandle'], '@');
        }
        // Make sure the sameAsLinks are indexed by the handle
        if (!empty($siteSettings['sameAsLinks'])) {
            $siteSettings['sameAsLinks'] = ArrayHelper::index($siteSettings['sameAsLinks'], 'handle');
        }
        // The site settings for the appropriate meta bundle
        $metaBundle = Seomatic::$plugin->metaBundles->getGlobalMetaBundle($siteId);
        if ($metaBundle) {
            if (is_array($siteSettings)) {
                $metaBundle->metaSiteVars->setAttributes($siteSettings);
            }

            Seomatic::$plugin->metaBundles->updateMetaBundle($metaBundle, $siteId);

            Seomatic::$plugin->clearAllCaches();
            Craft::$app->getSession()->setNotice(Craft::t('seomatic', 'SEOmatic site settings saved.'));
        }

        return $this->redirectToPostedUrl();
    }

    /**
     * Plugin settings
     *
     * @return Response The rendered result
     */
    public function actionPlugin(): Response
    {
        $variables = [];
        $pluginName = Seomatic::$settings->pluginName;
        $templateTitle = Craft::t('seomatic', 'Plugin Settings');
        // Asset bundle
        try {
            Seomatic::$view->registerAssetBundle(SeomaticAsset::class);
        } catch (InvalidConfigException $e) {
            Craft::error($e->getMessage(), __METHOD__);
        }
        $variables['baseAssetsUrl'] = Craft::$app->assetManager->getPublishedUrl(
            '@nystudio107/seomatic/assetbundles/seomatic/dist',
            true
        );
        // Basic variables
        $variables['fullPageForm'] = true;
        $variables['docsUrl'] = self::DOCUMENTATION_URL;
        $variables['pluginName'] = Seomatic::$settings->pluginName;
        $variables['title'] = $pluginName.' '.$templateTitle;
        $variables['crumbs'] = [
            [
                'label' => $pluginName,
                'url'   => UrlHelper::cpUrl('seomatic'),
            ],
            [
                'label' => $templateTitle,
                'url'   => UrlHelper::cpUrl('seomatic/settings'),
            ],
        ];
        $variables['selectedSubnavItem'] = 'plugin';
        $variables['settings'] = Seomatic::$settings;

        // Render the template
        return $this->renderTemplate('seomatic/settings/plugin/_edit', $variables);
    }


    /**
     * Tracking settings
     *
     * @param string $siteHandle
     *
     * @return Response The rendered result
     * @throws NotFoundHttpException
     * @throws \yii\web\ForbiddenHttpException
     */
    public function actionTracking(string $siteHandle = null): Response
    {
        $variables = [];
        // Get the site to edit
        $siteId = $this->getSiteIdFromHandle($siteHandle);

        $pluginName = Seomatic::$settings->pluginName;
        $templateTitle = Craft::t('seomatic', 'Tracking Scripts');
        // Asset bundle
        try {
            Seomatic::$view->registerAssetBundle(SeomaticAsset::class);
        } catch (InvalidConfigException $e) {
            Craft::error($e->getMessage(), __METHOD__);
        }
        $variables['baseAssetsUrl'] = Craft::$app->assetManager->getPublishedUrl(
            '@nystudio107/seomatic/assetbundles/seomatic/dist',
            true
        );
        // Basic variables
        $variables['fullPageForm'] = true;
        $variables['docsUrl'] = self::DOCUMENTATION_URL;
        $variables['pluginName'] = Seomatic::$settings->pluginName;
        $variables['title'] = $pluginName.' '.$templateTitle;
        $variables['crumbs'] = [
            [
                'label' => $pluginName,
                'url'   => UrlHelper::cpUrl('seomatic'),
            ],
            [
                'label' => $templateTitle,
                'url'   => UrlHelper::cpUrl('seomatic/tracking'),
            ],
        ];
        $variables['selectedSubnavItem'] = 'tracking';

        // Enabled sites
        $this->setMultiSiteVariables($siteHandle, $siteId, $variables);
        $variables['controllerHandle'] = 'tracking';

        // The script meta containers for the global meta bundle
        $metaBundle = Seomatic::$plugin->metaBundles->getGlobalMetaBundle(intval($variables['currentSiteId']));
        $variables['scripts'] = Seomatic::$plugin->metaBundles->getContainerDataFromBundle(
            $metaBundle,
            MetaScriptContainer::CONTAINER_TYPE
        );

        // Render the template
        return $this->renderTemplate('seomatic/settings/tracking/_edit', $variables);
    }

    /**
     * @return Response
     * @throws \yii\web\BadRequestHttpException
     */
    public function actionSaveTracking()
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();
        $siteId = $request->getParam('siteId');
        $scriptSettings = $request->getParam('scripts');

        // The site settings for the appropriate meta bundle
        $metaBundle = Seomatic::$plugin->metaBundles->getGlobalMetaBundle($siteId);
        if ($metaBundle) {
            foreach ($scriptSettings as $scriptHandle => $scriptData) {
                foreach ($metaBundle->metaContainers as $metaContainer) {
                    if ($metaContainer::CONTAINER_TYPE == MetaScriptContainer::CONTAINER_TYPE) {
                        $data = $metaContainer->getData($scriptHandle);
                        if ($data) {
                            foreach ($scriptData as $key => $value) {
                                if (is_array($value)) {
                                    foreach ($value as $varsKey => $varsValue) {
                                        $data->$key[$varsKey]['value'] = $varsValue;
                                    }
                                } else {
                                    $data->$key = $value;
                                }
                            }
                        }
                    }
                }
            }
            Seomatic::$plugin->metaBundles->updateMetaBundle($metaBundle, $siteId);

            Seomatic::$plugin->clearAllCaches();
            Craft::$app->getSession()->setNotice(Craft::t('seomatic', 'SEOmatic site settings saved.'));
        }

        return $this->redirectToPostedUrl();
    }

    // Protected Methods
    // =========================================================================

    /**
     * Set the text sources depending on the field settings
     *
     * @param string $elementName
     * @param        $globalsSettings
     * @param        $bundleSettings
     */
    protected function parseTextSources(string $elementName, &$globalsSettings, &$bundleSettings): void
    {
        $objectPrefix = '';
        if (!empty($elementName)) {
            $elementName .= '.';
            $objectPrefix = 'object.';
        }
        foreach (self::PULL_TEXT_FIELDS as $fields) {
            $fieldName = $fields['fieldName'];
            $source = $bundleSettings[$fieldName.'Source'] ?? '';
            $sourceField = $bundleSettings[$fieldName.'Field'] ?? '';
            if (!empty($source) && !empty($sourceField)) {
                $seoField = $fields['seoField'];
                switch ($source) {
                    case 'sameAsSeo':
                        $globalsSettings[$fieldName] =
                            '{seomatic.meta.'.$seoField.'}';
                        break;

                    case 'fromField':
                        $globalsSettings[$fieldName] =
                            '{seomatic.helper.extractTextFromField('
                            .$objectPrefix.$elementName.$sourceField
                            .')}';
                        break;

                    case 'summaryFromField':
                        $globalsSettings[$fieldName] =
                            '{seomatic.helper.extractSummary(seomatic.helper.extractTextFromField('
                            .$objectPrefix.$elementName.$sourceField
                            .'))}';
                        break;

                    case 'keywordsFromField':
                        $globalsSettings[$fieldName] =
                            '{seomatic.helper.extractKeywords(seomatic.helper.extractTextFromField('
                            .$objectPrefix.$elementName.$sourceField
                            .'))}';
                        break;

                    case 'fromCustom':
                        break;
                }
            }
        }
    }

    /**
     * Set the image sources depending on the field settings
     *
     * @param $elementName
     * @param $globalsSettings
     * @param $bundleSettings
     * @param $siteId
     */
    protected function parseImageSources($elementName, &$globalsSettings, &$bundleSettings, $siteId): void
    {
        $objectPrefix = '';
        if (!empty($elementName)) {
            $elementName .= '.';
            $objectPrefix = 'object.';
        }
        foreach (self::PULL_ASSET_FIELDS as $fields) {
            $fieldName = $fields['fieldName'];
            $source = $bundleSettings[$fieldName.'Source'] ?? '';
            $ids = $bundleSettings[$fieldName.'Ids'] ?? [];
            $sourceField = $bundleSettings[$fieldName.'Field'] ?? '';
            if (!empty($source) && !empty($sourceField)) {
                $transformImage = $bundleSettings[$fieldName.'Transform'];
                $seoField = $fields['seoField'];
                $transformName = $fields['transformName'];
                // Special-case Twitter transforms
                if ($transformName == 'twitter') {
                    $transformName = 'twitter-summary';
                    if ($globalsSettings['twitterCard'] == 'summary_large_image') {
                        $transformName = 'twitter-large';
                    }
                }
                switch ($source) {
                    case 'sameAsSeo':
                        if ($transformImage) {
                            $seoSource = $bundleSettings[$seoField.'Source'];
                            $seoIds = $bundleSettings[$seoField.'Ids'];
                            $seoSourceField = $bundleSettings[$seoField.'Field'] ?? '';
                            switch ($seoSource) {
                                case 'fromField':
                                    if (!empty($seoSourceField)) {
                                        $globalsSettings[$fieldName] = '{seomatic.helper.socialTransform('
                                            .$objectPrefix.$elementName.$seoSourceField.'.one()'
                                            .', "'.$transformName.'"'
                                            .', '.$siteId.')}';
                                    }
                                    break;
                                case 'fromAsset':
                                    if (!empty($seoIds)) {
                                        $globalsSettings[$fieldName] = '{seomatic.helper.socialTransform('
                                            .$seoIds[0]
                                            .', "'.$transformName.'"'
                                            .', '.$siteId.')}';
                                    }
                                    break;
                                default:
                                    $globalsSettings[$fieldName] = '{seomatic.meta.'.$seoField.'}';
                                    break;
                            }
                        } else {
                            $globalsSettings[$fieldName] = '{seomatic.meta.'.$seoField.'}';
                        }
                        break;
                    case 'fromField':
                        if ($transformImage) {
                            if (!empty($sourceField)) {
                                $globalsSettings[$fieldName] = '{seomatic.helper.socialTransform('
                                    .$objectPrefix.$elementName.$sourceField.'.one()'
                                    .', "'.$transformName.'"'
                                    .', '.$siteId.')}';
                            }
                        } else {
                            if (!empty($sourceField)) {
                                $globalsSettings[$fieldName] = '{'
                                    .$elementName.$sourceField.'.one().url'
                                    .'}';
                            }
                        }
                        break;
                    case 'fromAsset':
                        if ($transformImage) {
                            if (!empty($ids)) {
                                $globalsSettings[$fieldName] = '{seomatic.helper.socialTransform('
                                    .$ids[0]
                                    .', "'.$transformName.'"'
                                    .', '.$siteId.')}';
                            }
                        } else {
                            if (!empty($ids)) {
                                $globalsSettings[$fieldName] = '{{ craft.app.assets.assetById('
                                    .$ids[0]
                                    .', '.$siteId.').url }}';
                            }
                        }
                        break;
                }
            }
        }
    }

    /**
     * @param array $variables
     */
    protected function setGlobalFieldSourceVariables(array &$variables)
    {
        $variables['textFieldSources'] = array_merge(
            ['globalsGroup' => ['optgroup' => 'Globals Fields']],
            FieldHelper::fieldsOfTypeFromGlobals(
                FieldHelper::TEXT_FIELD_CLASS_KEY,
                false
            )
        );
        $variables['assetFieldSources'] = array_merge(
            ['globalsGroup' => ['optgroup' => 'Globals Fields']],
            FieldHelper::fieldsOfTypeFromGlobals(
                FieldHelper::ASSET_FIELD_CLASS_KEY,
                false
            )
        );
    }

    /**
     * @param string $sourceBundleType
     * @param string $sourceHandle
     * @param string $groupName
     * @param array  $variables
     */
    protected function setContentFieldSourceVariables(
        string $sourceBundleType,
        string $sourceHandle,
        string $groupName,
        array &$variables
    ) {
        $variables['textFieldSources'] = array_merge(
            ['entryGroup' => ['optgroup' => $groupName.' Fields'], 'title' => 'Title'],
            FieldHelper::fieldsOfTypeFromSource(
                $sourceBundleType,
                $sourceHandle,
                FieldHelper::TEXT_FIELD_CLASS_KEY,
                false
            )
        );
        $variables['assetFieldSources'] = array_merge(
            ['entryGroup' => ['optgroup' => $groupName.' Fields']],
            FieldHelper::fieldsOfTypeFromSource(
                $sourceBundleType,
                $sourceHandle,
                FieldHelper::ASSET_FIELD_CLASS_KEY,
                false
            )
        );
        $variables['assetVolumeTextFieldSources'] = array_merge(
            ['entryGroup' => ['optgroup' => 'Asset Volume Fields'], 'title' => 'Title'],
            FieldHelper::fieldsOfTypeFromAssetVolumes(
                FieldHelper::TEXT_FIELD_CLASS_KEY,
                false
            )
        );
    }

    /**
     * @param string $sourceBundleType
     * @param string $sourceHandle
     *
     * @return string
     */
    protected function uriFromSourceBundle(string $sourceBundleType, string $sourceHandle): string
    {
        $uri = '';
        // Pick an Element to be used for the preview
        switch ($sourceBundleType) {
            case MetaBundles::GLOBAL_META_BUNDLE:
                $uri = MetaBundles::GLOBAL_META_BUNDLE;
                break;

            case MetaBundles::SECTION_META_BUNDLE:
                $entry = Entry::find()->section($sourceHandle)->one();
                if ($entry) {
                    $uri = $entry->uri;
                }
                break;

            case MetaBundles::CATEGORYGROUP_META_BUNDLE:
                $category = Category::find()->group($sourceHandle)->one();
                if ($category) {
                    $uri = $category->uri;
                }
                break;
            // @TODO: handle commerce products
        }
        if (($uri == '__home__') || ($uri === null)) {
            $uri = '/';
        }

        return $uri;
    }

    /**
     * @param string $siteHandle
     * @param        $siteId
     * @param        $variables
     *
     * @throws \yii\web\ForbiddenHttpException
     */
    protected function setMultiSiteVariables($siteHandle, &$siteId, array &$variables): void
    {
        // Enabled sites
        $sites = Craft::$app->getSites();
        if (Craft::$app->getIsMultiSite()) {
            // Set defaults based on the section settings
            $variables['enabledSiteIds'] = [];
            $variables['siteIds'] = [];

            /** @var Site $site */
            foreach ($sites->getEditableSiteIds() as $editableSiteId) {
                $variables['enabledSiteIds'][] = $editableSiteId;
                $variables['siteIds'][] = $editableSiteId;
            }

            // Make sure the $siteId they are trying to edit is in our array of editable sites
            if (!in_array($siteId, $variables['enabledSiteIds'])) {
                if (!empty($variables['enabledSiteIds'])) {
                    $siteId = reset($variables['enabledSiteIds']);
                } else {
                    $this->requirePermission('editSite:'.$siteId);
                }
            }
        }
        // Set the currentSiteId and currentSiteHandle
        $variables['currentSiteId'] = empty($siteId) ? Craft::$app->getSites()->currentSite->id : $siteId;
        $variables['currentSiteHandle'] = empty($siteHandle)
            ? Craft::$app->getSites()->currentSite->handle
            : $siteHandle;

        // Page title
        $variables['showSites'] = (
            Craft::$app->getIsMultiSite() &&
            count($variables['enabledSiteIds'])
        );

        if ($variables['showSites']) {
            $variables['sitesMenuLabel'] = Craft::t(
                'site',
                $sites->getSiteById(intval($variables['currentSiteId']))->name
            );
        } else {
            $variables['sitesMenuLabel'] = '';
        }
    }

    /**
     * Return an array of Asset elements from an array of element IDs
     *
     * @param array|string $assetIds
     * @param int          $siteId
     *
     * @return array
     */
    protected function assetElementsFromIds($assetIds, int $siteId)
    {
        $elements = Craft::$app->getElements();
        $assets = [];
        if (!empty($assetIds)) {
            foreach ($assetIds as $assetId) {
                $assets[] = $elements->getElementById($assetId, Asset::class, $siteId);
            }
        }

        return $assets;
    }

    /**
     * Return a siteId from a siteHandle
     *
     * @param string $siteHandle
     *
     * @return int|null
     * @throws NotFoundHttpException
     */
    protected function getSiteIdFromHandle($siteHandle)
    {
        // Get the site to edit
        if ($siteHandle !== null) {
            $site = Craft::$app->getSites()->getSiteByHandle($siteHandle);
            if (!$site) {
                throw new NotFoundHttpException('Invalid site handle: '.$siteHandle);
            }
            $siteId = $site->id;
        } else {
            $siteId = Craft::$app->getSites()->currentSite->id;
        }

        return $siteId;
    }
}
