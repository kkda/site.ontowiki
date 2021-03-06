<?php
/**
 * This file is part of the {@link http://ontowiki.net OntoWiki} project.
 *
 * @copyright Copyright (c) 2011, {@link http://aksw.org AKSW}
 * @license http://opensource.org/licenses/gpl-license.php GNU General Public License (GPL)
 */

/**
 * The main controller class for the site component. This class
 * provides an action to render a given resource
 *
 * @category   OntoWiki
 * @package    OntoWiki_extensions_components_site
 */
class SiteController extends OntoWiki_Controller_Component
{
    /**
     * The main template filename
     */
    const MAIN_TEMPLATE_NAME = 'layout.phtml';

    /**
     * The model URI of the selected model or the uri which is given
     * by the m parameter
     *
     * @var string|null
     */
    private $_modelUri = null;

    /**
     * The selected model or the model which is given
     * by the m parameter
     */
    private $_model = null;

    /**
     * The resource URI of the requested resource or the uri which is given
     * by the r parameter
     *
     * @var string|null
     */
    private $_resourceUri = null;

    /**
     * relative Path to the extension template folder
     */
    private $_relativeTemplatePath = 'templates';
    
    /**
     * The site id which is part of the request URI as well as the template structure
     *
     * @var string|null
     */
    private $_site = null;

    public function init()
    {
        parent::init();
        $this->_helper->viewRenderer->setNoRender();
        $this->_helper->layout()->disableLayout();
        $this->_relativeTemplatePath = $this->_owApp->extensionManager->getExtensionConfig('site')->templates;
    }

    /*
     * to allow multiple template sets, every action is mapped to a template directory
     */
    public function __call($method, $args)
    {
        $action = $this->_request->getActionName();
        $router = $this->_owApp->getBootstrap()->getResource('Router');

        if ($router->hasRoute('empty')) {
            $emptyRoute    = $router->getRoute('empty');
            $defaults      = $emptyRoute->getDefaults();
            $defaultAction = $defaults['action'];
        }

        if (empty($action) || (isset($defaultAction) && $action === $defaultAction) || $action === 'index') {
            // use default site for empty or default action (index)
            $this->_site = $this->_privateConfig->defaultSite;
        } else {
            // use action as site otherwise
            $this->_site  = $action;
        }

        $this->getComponentHelper()->setSite($this->_site);

        $templatePath = $this->_owApp->extensionManager->getComponentTemplatePath('site');
        $mainTemplate = sprintf('%s/%s', $this->_site, self::MAIN_TEMPLATE_NAME);

        if (is_readable($templatePath . $mainTemplate)) {
            $this->moduleContext = 'site.' . $this->_site;
            // $this->addModuleContext($this->moduleContext);

            $this->_loadModel();
            $this->_loadResource();

            // Here we start the object cache id
            $siteModuleObjectCacheIdSource = $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
            $siteModuleObjectCacheId = 'site_' . md5($siteModuleObjectCacheIdSource);

            // try to load the cached value
            $erfurtObjectCache = OntoWiki::getInstance()->erfurt->getCache();
            $erfurtQueryCache  = OntoWiki::getInstance()->erfurt->getQueryCache();
            $cachePageContent  = $erfurtObjectCache->load($siteModuleObjectCacheId);
            if ($cachePageContent != false) {
                $this->_response->setBody($cachePageContent); // send cached body instead of generating a new one
                return;
            } else {
                $erfurtQueryCache->startTransaction($siteModuleObjectCacheId);
            }

            $moduleTemplatePath = $this->_componentRoot
                                . $this->_relativeTemplatePath
                                . DIRECTORY_SEPARATOR
                                . $this->_privateConfig->defaultSite
                                . DIRECTORY_SEPARATOR
                                . 'modules';

            // add module template override path
            if (is_readable($moduleTemplatePath)) {
                $scriptPaths = $this->view->getScriptPaths();
                array_push($scriptPaths, $moduleTemplatePath);
                $this->view->setScriptPath($scriptPaths);
            }

            // with assignment, direct access is possible ($this->basePath).
            $this->view->assign($this->_getTemplateData());
            // this allows for easy re-assignment of everything
            $this->view->templateData = $this->_getTemplateData();

            // generate the page body
            $bodyContent = $this->view->render($mainTemplate);

            // save the page body as an object value for the object cache
            $erfurtObjectCache->save($bodyContent, $siteModuleObjectCacheId);
            // close the object cache transaction
            $erfurtQueryCache->endTransaction($siteModuleObjectCacheId);

            // set the page content
            $this->_response->setBody($bodyContent);
            $this->_response->setHeader('Content-Type', 'text/html; encoding=utf-8');
        } else {
            $this->_response->setRawHeader('HTTP/1.0 404 Not Found');
            $this->_response->setBody($this->view->render('404.phtml'));
        }
    }


    private function _getTemplateData()
    {
        // prepare namespace array with presets of rdf, rdfs and owl
        $namespaces = array(
            'rdf'    => 'http://www.w3.org/1999/02/22-rdf-syntax-ns#',
            'rdfs'   => 'http://www.w3.org/2000/01/rdf-schema#',
            'owl'    => 'http://www.w3.org/2002/07/owl#'
        );
        foreach ($this->_model->getNamespaces() as $ns => $prefix) {
            $namespaces[$prefix] = $ns;
        }

        // this template data is given to ALL templates (with renderx)
        $templateData           = array(
            'siteId'            => $this->_site,
            'siteConfig'        => $this->_getSiteConfig(),
            'generator'         => 'OntoWiki ' . $this->_config->version->number,
            'pingbackUrl'       => $this->_owApp->getUrlBase() . 'pingback/ping',
            'wikiBaseUrl'       => $this->_owApp->getUrlBase(),
            'themeUrlBase'      => $this->view->themeUrlBase,
            'libraryUrlBase'    => $this->view->libraryUrlBase,
            'basePath'          => sprintf('%s%s/%s', $this->_componentRoot, $this->_relativeTemplatePath, $this->_site),
            'baseUri'           => sprintf('%s%s/%s', $this->_componentUrlBase, $this->_relativeTemplatePath, $this->_site),
            'context'           => $this->moduleContext,
            'namespaces'        => $namespaces,
            'model'             => $this->_model,
            'modelUri'          => $this->_modelUri,
            'title'             => $this->_resource->getTitle(),
            'resourceUri'       => (string) $this->_resourceUri,
            'description'       => $this->_resource->getDescription(),
            'descriptionHelper' => $this->_resource->getDescriptionHelper(),

            'site'              => array(
                                            'index' => 0,
                                            'name' => 'Home'
                                        ),
            'navigation'        => array(),
            'options'           => array(),
        );

        return $templateData;
    }


    protected function _loadModel()
    {
        $siteConfig = $this->_getSiteConfig();

        // m is automatically used and selected
        if ((!isset($this->_request->m)) && (!$this->_owApp->selectedModel)) {
            // TODO: what if no site model configured?
            if (!Erfurt_Uri::check($siteConfig['model'])) {
                $site = $this->_privateConfig->defaultSite;
                $root = $this->getComponentHelper()->getComponentRoot();
                $configFilePath = sprintf('%s%s/%s/%s', $root, $this->_relativeTemplatePath, $site, SiteHelper::SITE_CONFIG_FILENAME);
                throw new OntoWiki_Exception(
                    'No model selected! Please, configure a site model by setting the option '
                    . '"model=..." in "' . $configFilePath . '" or specify parameter m in the URL.'
                );
            } else {
                // setup the model
                $this->_modelUri = $siteConfig['model'];
                $store = OntoWiki::getInstance()->erfurt->getStore();
                $this->_model = $store->getModel($this->_modelUri);
                OntoWiki::getInstance()->selectedModel = $this->_model;
            }
        } else {
            $this->_model = $this->_owApp->selectedModel;
            $this->_modelUri = (string) $this->_owApp->selectedModel;
        }
    }

    protected function _loadResource()
    {
        // r is automatically used and selected, if not then we use the model uri as starting point
        if ((!isset($this->_request->r)) && (!$this->_owApp->selectedResource)) {
            OntoWiki::getInstance()->selectedResource = new OntoWiki_Resource($this->_modelUri, $this->_model);
        }
        $this->_resource = $this->_owApp->selectedResource;
        $this->_resourceUri = (string) $this->_owApp->selectedResource;
    }

    protected function _getSiteConfig()
    {
        return $this->getComponentHelper()->getSiteConfig();
    }

}
