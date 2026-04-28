<?php

declare(strict_types=1);

namespace F7\Preview\Backend\EventListener;

use TYPO3\CMS\Backend\Controller\Event\ModifyPageLayoutContentEvent;
use F7\Preview\Utility\PreviewUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Fluid\View\StandaloneView;
use TYPO3\CMS\Core\Page\PageRenderer;

final  class PreviewEventListener
{
    /**
     * Page is not published in these languages.
     *
     * @var array
     */
    protected $notPublishedLanguages = [];

    /**
     * Properties of all language variants of this page
     *
     * @var array
     */
    protected $languageProperties = [];

    public function __construct(
        private readonly PageRenderer $pageRenderer,
    ) {}

    public function __invoke(ModifyPageLayoutContentEvent $event): void
    {
        // Get the current page ID
        $pageId = (int)($event->getRequest()->getQueryParams()['id'] ?? 0);

        // remove all outdated preview links
        PreviewUtility::removeOutdatedLinks();

        $backendUriBuilder = GeneralUtility::makeInstance(\TYPO3\CMS\Backend\Routing\UriBuilder::class);

        $site = GeneralUtility::makeInstance(SiteFinder::class)->getSiteByPageId($pageId);
        $pageIsTranslatedInLanguages = $this->getLanguageVariants($pageId);

        // If all translations of this page are already published, do not render anything and leave.
        if (empty($this->notPublishedLanguages)) {
            return;
        }

        $languages = [];
        foreach ($site->getLanguages() as $language) {
            if (in_array($language->getLanguageId(), $pageIsTranslatedInLanguages) && in_array($language->getLanguageId(), $this->notPublishedLanguages)) {
                $linkInformation = PreviewUtility::getPreviewLink($pageId, $language->getLanguageId());
                if ($linkInformation === []) {
                    $actionUri = $backendUriBuilder->buildUriFromRoutePath(
                        '/tx_preview/addLink',
                        [
                            'addLink' => [
                                'page' => $pageId,
                                'language' => $language->getLanguageId(),
                            ]
                        ]
                    );
                } else {
                    $actionUri = $backendUriBuilder->buildUriFromRoutePath(
                        '/tx_preview/removeLink',
                        [
                            'removeLink' => [
                                'page' => $pageId,
                                'language' => $language->getLanguageId(),
                            ]
                        ]
                    );
                }

                $parameters = [
                    'tx_preview' => $linkInformation['hash'] ?? '',
                    '_language' => $language->getLanguageId()
                ];

                // is the page restricted by start- and/ or endtime? Then add page id and simulate time parameter
                if ($this->languageProperties[$language->getLanguageId()]['endtime'] > 0) {
                    $parameters['id'] = $pageId;
                    $parameters['ADMCMD_simTime'] = $this->languageProperties[$language->getLanguageId()]['endtime'] - 1;
                } elseif ($this->languageProperties[$language->getLanguageId()]['starttime'] > 0) {
                    $parameters['id'] = $pageId;
                    $parameters['ADMCMD_simTime'] = $this->languageProperties[$language->getLanguageId()]['starttime'] + 1;
                }

                $languages[] = [
                    'title' => $language->getNavigationTitle(),
                    'flagIdentifier' => $language->getFlagIdentifier(),
                    'previewLink' => PreviewUtility::getPreviewLink($pageId, $language->getLanguageId()),
                    'url' => (string)$site->getRouter()->generateUri($pageId, $parameters),
                    'action' => $actionUri
                ];
            }
        }

        $this->pageRenderer->loadJavaScriptModule('@f7media/preview/Preview.js');

        $view = GeneralUtility::makeInstance(StandaloneView::class);
        $view->setTemplateRootPaths(['EXT:preview/Resources/Private/Templates/Backend']);

        $view->setTemplate('Show');
        $view->assignMultiple([
            'languages' => $languages,
        ]);

        $event->addHeaderContent($view->render());
    }

    /**
     * This method returns an array with all language ids the current page is translated to.
     */
    private function getLanguageVariants(int $pageId): array
    {

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, (int)$this->getBackendUser()->workspace));
        $queryBuilder->select(
            'uid',
            $GLOBALS['TCA']['pages']['ctrl']['languageField'],
            'hidden',
            'starttime',
            'endtime'
        )
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq(
                    $GLOBALS['TCA']['pages']['ctrl']['transOrigPointerField'],
                    $queryBuilder->createNamedParameter($pageId, Connection::PARAM_INT)
                )
            )
            ->orWhere(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter($pageId, Connection::PARAM_INT)
                )
            );
        $statement = $queryBuilder->executeQuery();
        $languages = [];
        $notPublishedLanguages = [];
        $languageProperties = [];
        while ($row = $statement->fetchAssociative()) {
            if ($this->isLanguageHidden($row, date('U'))) {
                $notPublishedLanguages[] = (int)$row[$GLOBALS['TCA']['pages']['ctrl']['languageField']];
                $languageProperties[(int)$row[$GLOBALS['TCA']['pages']['ctrl']['languageField']]] = [
                    'starttime' => $row['starttime'],
                    'endtime' => $row['endtime'],
                    'hidden' => $row['hidden'],
                ];
            }
            $languages[] = (int)$row[$GLOBALS['TCA']['pages']['ctrl']['languageField']];
        }
        $this->notPublishedLanguages = $notPublishedLanguages;
        $this->languageProperties = $languageProperties;
        return $languages;
    }

    public function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }

    public function isLanguageHidden(array $page, $date): bool
    {
        if (!(bool)$page['hidden']
            && !(bool)($page['starttime'] > $date)
            && !(bool)($page['endtime'] > 0 && $page['endtime'] < $date)) {
            return false;
        }

        return true;
    }
}
