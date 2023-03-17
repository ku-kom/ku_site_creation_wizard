<?php

declare(strict_types=1);

namespace CopenhagenUniversity\SiteWizard\Backend\Controller;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use TYPO3\CMS\Backend\Attribute\Controller;
use TYPO3\CMS\Backend\Configuration\SiteTcaConfiguration;
use TYPO3\CMS\Backend\Form\FormDataCompiler;
use TYPO3\CMS\Backend\Form\FormDataGroup\SiteConfigurationDataGroup;
use TYPO3\CMS\Backend\Form\FormResultCompiler;
use TYPO3\CMS\Backend\Form\NodeFactory;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Configuration\SiteConfiguration;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[Controller]
class SiteWizardController
{
    protected const ALLOWED_ACTIONS = ['new', 'create'];

    public function __construct(
        protected readonly SiteFinder $siteFinder,
        protected readonly IconFactory $iconFactory,
        protected readonly UriBuilder $uriBuilder,
        protected readonly ModuleTemplateFactory $moduleTemplateFactory
    ) {
    }

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $action = $request->getQueryParams()['action'] ?? $request->getParsedBody()['action'] ?? 'new';
        if (!in_array($action, self::ALLOWED_ACTIONS, true)) {
            return new HtmlResponse('Action not allowed', 400);
        }
        return $this->{$action . 'Action'}($request);
    }

    public function newAction(ServerRequestInterface $request): ResponseInterface
    {
        $GLOBALS['TCA'] = array_merge($GLOBALS['TCA'], GeneralUtility::makeInstance(SiteTcaConfiguration::class)->getTca());
        $view = $this->moduleTemplateFactory->create($request);
        $this->configureDocHeader($view, $request->getAttribute('normalizedParams')->getRequestUri());
        $view->setTitle(
            'Site Wizard'
        );
        $returnUrl = $this->uriBuilder->buildUriFromRoute('site_wizard');

        $formDataGroup = GeneralUtility::makeInstance(SiteConfigurationDataGroup::class);
        $formDataCompiler = GeneralUtility::makeInstance(FormDataCompiler::class, $formDataGroup);
        $formDataCompilerInput = [
            'tableName' => 'site_wizard',
            'vanillaUid' => 0,
            'command' => 'new',
            'returnUrl' => (string)$returnUrl,
            'defaultValues' => []
        ];
        $formData = $formDataCompiler->compile($formDataCompilerInput);
        $nodeFactory = GeneralUtility::makeInstance(NodeFactory::class);
        $formData['renderType'] = 'outerWrapContainer';
        $formResult = $nodeFactory->create($formData)->render();
        // Needed to be set for 'onChange="reload"' and reload on type change to work
        $formResult['doSaveFieldName'] = 'doSave';
        $formResultCompiler = GeneralUtility::makeInstance(FormResultCompiler::class);
        $formResultCompiler->mergeResult($formResult);
        $formResultCompiler->addCssFiles();

        $view->assignMultiple([
            'formEngineHtml' => $formResult['html'],
            'formEngineFooter' => $formResultCompiler->printNeededJSFunctions()
        ]);
        return $view->renderResponse('SiteWizard/New');
    }

    public function createAction(ServerRequestInterface $request): ResponseInterface
    {
        $parsedBody = $request->getParsedBody();
        $data = $parsedBody['data'];
        $values = current($data['site_wizard']);

        $newId = 'NEW_' . rand();
        $dataMap = [
            'pages' => [
                $newId => [
                    'pid' => 0,
                    'is_siteroot' => 1,
                    'title' => $values['websiteTitle'],
                    'hidden' => 0
                ]
            ]
        ];

        /** @var DataHandler $dataHandler */
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($dataMap, []);
        $dataHandler->process_datamap();
        BackendUtility::setUpdateSignal('updatePageTree');

        $uid = (int) $dataHandler->substNEWwithIDs[$newId];
        $siteIdentifier = sprintf('site-%s', $uid);

        $siteConfigurationManager = GeneralUtility::makeInstance(SiteConfiguration::class);
        $siteConfigurationManager->write(
            $siteIdentifier,
            self::getDefaultSiteConfiguration($uid, $values['websiteTitle'], $values['base'])
        );

        return new RedirectResponse($this->uriBuilder->buildUriFromRoute('site_configuration', ['action' => 'edit', 'site' => $siteIdentifier]));
    }

    protected static function getDefaultSiteConfiguration(int $rootPageId, string $websiteTitle, string $base): array
    {
        return [
            'rootPageId' => $rootPageId,
            'base' => $base,
            'websiteTitle' => $websiteTitle,
            'languages' => [
                0 => [
                    'title' => 'Dansk',
                    'enabled' => true,
                    'languageId' => 0,
                    'base' => '/',
                    'typo3Language' => 'default',
                    'locale' => 'da_DK.UTF-8',
                    'iso-639-1' => 'da',
                    'navigationTitle' => 'Dansk',
                    'hreflang' => 'da-dk',
                    'direction' => 'ltr',
                    'flag' => 'dk',
                ],
            ],
            'errorHandling' => [],
            'routes' => [],
        ];
    }

    protected function configureDocHeader(ModuleTemplate $view): void
    {
        $buttonBar = $view->getDocHeaderComponent()->getButtonBar();
        $lang = $this->getLanguageService();
        $closeButton = $buttonBar->makeLinkButton()
            ->setHref('#')
            ->setClasses('t3js-editform-close')
            ->setTitle($lang->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:rm.closeDoc'))
            ->setShowLabelText(true)
            ->setIcon($this->iconFactory->getIcon('actions-close', Icon::SIZE_SMALL));
        $saveButton = $buttonBar->makeInputButton()
            ->setTitle($lang->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:rm.saveDoc'))
            ->setName('_savedok')
            ->setValue('1')
            ->setShowLabelText(true)
            ->setForm('siteWizardController')
            ->setIcon($this->iconFactory->getIcon('actions-document-save', Icon::SIZE_SMALL));
        #$buttonBar->addButton($closeButton);
        $buttonBar->addButton($saveButton, ButtonBar::BUTTON_POSITION_LEFT, 2);
    }

    protected function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
