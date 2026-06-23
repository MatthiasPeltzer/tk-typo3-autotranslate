<?php

declare(strict_types=1);

namespace ThieleUndKlose\Autotranslate\Controller;

use DateTime;
use DateTimeZone;
use Exception;
use ThieleUndKlose\Autotranslate\Domain\Model\BatchItem;
use ThieleUndKlose\Autotranslate\Domain\Repository\BatchItemRepository;
use ThieleUndKlose\Autotranslate\Domain\Repository\LogRepository;
use ThieleUndKlose\Autotranslate\Service\BatchTranslationRunner;
use ThieleUndKlose\Autotranslate\Service\BatchTranslationService;
use ThieleUndKlose\Autotranslate\Service\TranslationCacheService;
use ThieleUndKlose\Autotranslate\Utility\DeeplApiHelper;
use ThieleUndKlose\Autotranslate\Utility\LogUtility;
use ThieleUndKlose\Autotranslate\Utility\PageUtility;
use ThieleUndKlose\Autotranslate\Utility\TranslationHelper;
use ThieleUndKlose\Autotranslate\Utility\Translator;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Http\PropagateResponseException;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager;

/**
 * Base controller for batch translation backend module
 */
class BatchTranslationBaseController extends ActionController
{
    protected const MODULE_NAME = 'web_autotranslate';
    protected const MENU_LEVEL_ITEMS = [0, 1, 2, 3, 4, 250];

    /** @var list<string> */
    private const MUTATION_ARGUMENTS = ['clearCache', 'delete', 'execute', 'reset', 'deleteAll'];

    protected ?TranslationCacheService $translationCacheService = null;
    protected ?PersistenceManager $persistenceManager = null;
    protected ?BatchTranslationService $batchTranslationService = null;
    protected ?BatchItemRepository $batchItemRepository = null;
    protected ?LogRepository $logRepository = null;

    /** @var array<string, mixed> */
    protected array $queryParams = [];
    protected int $pageUid = 0;
    protected int $levels = 0;
    protected string $moduleName = self::MODULE_NAME;
    /** @var array<string, mixed> */
    protected array $deeplApiKeyDetails = [];

    // =========================================================================
    // Dependency Injection
    // =========================================================================

    public function injectTranslationCacheService(TranslationCacheService $service): void
    {
        $this->translationCacheService = $service;
    }

    public function injectPersistenceManager(PersistenceManager $manager): void
    {
        $this->persistenceManager = $manager;
    }

    public function injectBatchTranslationService(BatchTranslationService $service): void
    {
        $this->batchTranslationService = $service;
    }

    public function injectBatchItemRepository(BatchItemRepository $repository): void
    {
        $this->batchItemRepository = $repository;
    }

    public function injectLogRepository(LogRepository $repository): void
    {
        $this->logRepository = $repository;
    }

    // =========================================================================
    // Initialization
    // =========================================================================

    /**
     * @return array<string, mixed>
     */
    public function getLogData(): array
    {
        $this->handleLogActions();

        $pageRecord = BackendUtility::getRecordWSOL('pages', $this->pageUid);
        $logs = $this->processLogs($this->logRepository->findAll());

        return [
            'pageUid' => $this->pageUid,
            'levels' => $this->levels,
            'pageTitle' => $pageRecord['title'] ?? '',
            'moduleName' => $this->moduleName,
            'logItemsCount' => $this->logRepository->countAll(),
            'logsGroupedByRequestId' => $this->groupLogsByRequestId($logs),
        ];
    }

    protected function handleLogActions(): void
    {
        if ($this->warnIfGetMutationAttempt()) {
            return;
        }

        if (!$this->isPostRequest()) {
            return;
        }

        $shouldReload = false;

        if ($this->hasMutationParam('delete')) {
            $requestIds = GeneralUtility::trimExplode(',', (string)$this->getMutationParam('delete'));
            foreach ($requestIds as $requestId) {
                $this->logRepository->deleteByRequestId($requestId);
            }
            $this->showSuccess('Successfully deleted', sprintf('%d log entries were deleted.', count($requestIds)));
            $shouldReload = true;
        }

        if ($this->hasMutationParam('deleteAll')) {
            $this->logRepository->deleteAll();
            $this->showSuccess('Successfully deleted', 'All log entries were deleted.');
            $shouldReload = true;
        }

        if ($shouldReload) {
            $this->persistAndReload();
        }
    }

    private function showSuccess(string $title, string $message): void
    {
        $this->addFlashMessage($title, $message, ContextualFeedbackSeverity::OK);
    }

    // =========================================================================
    // Data Retrieval
    // =========================================================================

    private function persistAndReload(): void
    {
        $this->persistenceManager->persistAll();
        $this->reloadPage();
    }

    protected function reloadPage(): void
    {
        $this->redirectToPage($this->pageUid);
    }

    /**
     * @throws PropagateResponseException
     */
    protected function redirectToPage(int $pageUid): never
    {
        $uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);
        $uri = (string)$uriBuilder->buildUriFromRoute($this->moduleName, [
            'id' => $pageUid,
            'action' => $this->request->getControllerActionName(),
        ]);

        throw new PropagateResponseException(
            new RedirectResponse($uri, 303),
            1738900000
        );
    }

    // =========================================================================
    // Action Handlers
    // =========================================================================

    /**
     * @param list<array<string, mixed>> $logs
     * @return list<array<string, mixed>>
     */
    private function processLogs(array $logs): array
    {
        return array_map(
            /** @param array<string, mixed> $log */
            function (array $log): array {
                $log['time_seconds'] = (int)($log['time_micro'] ?? 0);
                $log['dataDecoded'] = $this->decodeLogData($log['data'] ?? '');
                $log['dataDecodedJson'] = !empty($log['dataDecoded'])
                    ? json_encode($log['dataDecoded'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                    : '';
                $log['parsed_message'] = LogUtility::interpolate($log['message'] ?? '', $log['dataDecoded']);
                $log['formattedDate'] = $this->formatLogDate($log['time_micro'] ?? null);
                return $log;
            },
            $logs
        );
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decodeLogData(string $data): array
    {
        if (empty($data)) {
            return [];
        }

        $decoded = json_decode($data, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function formatLogDate(?float $timeMicro): string
    {
        if (!$timeMicro) {
            return '';
        }

        $dateTime = DateTime::createFromFormat('U.u', sprintf('%.6f', $timeMicro));
        return $dateTime ? $dateTime->format('Y-m-d H:i:s.u') : '';
    }

    /**
     * @param list<array<string, mixed>> $logs
     * @return array<array-key, list<array<string, mixed>>>
     */
    private function groupLogsByRequestId(array $logs): array
    {
        $grouped = [];

        foreach ($logs as $log) {
            $requestId = $log['request_id'] ?? 'unknown';
            $grouped[$requestId][] = $log;
        }

        return $grouped;
    }

    /**
     * @return array<string, mixed>
     */
    public function getBatchTranslationData(): array
    {
        $this->handleBatchActions();

        if ($this->pageUid === 0) {
            return [];
        }

        $site = $this->getSiteConfiguration();
        if (!$site) {
            return ['moduleName' => $this->moduleName];
        }

        $languages = $this->getAccessibleLanguages($site);
        $batchItems = $this->getAccessibleBatchItems($languages);
        $pageRecord = BackendUtility::getRecordWSOL('pages', $this->pageUid);

        return [
            'moduleName' => $this->moduleName,
            'rootPageId' => $site->getRootPageId(),
            'batchItems' => $this->batchItemRepository->findAll(),
            'batchItemsRecursive' => $batchItems,
            'pageUid' => $this->pageUid,
            'levels' => $this->levels,
            'queryParams' => $this->queryParams,
            'pageTitle' => $pageRecord['title'] ?? '',
            'createForm' => $this->buildCreateFormData($languages, $pageRecord),
        ];
    }

    protected function handleBatchActions(): void
    {
        if ($this->warnIfGetMutationAttempt()) {
            $this->showCacheInfo();
            return;
        }

        if (!$this->isPostRequest()) {
            $this->showCacheInfo();
            return;
        }

        $shouldReload = false;

        if ($this->hasMutationParam('clearCache')) {
            $shouldReload = $this->handleClearCache();
        }

        if ($this->hasMutationParam('delete')) {
            $shouldReload = $this->handleDelete() || $shouldReload;
        }

        if ($this->hasMutationParam('execute')) {
            $this->handleExecute();
            $shouldReload = true;
        }

        if ($this->hasMutationParam('reset')) {
            $this->handleReset();
            $shouldReload = true;
        }

        if ($shouldReload) {
            $this->persistAndReload();
        }

        $this->showCacheInfo();
    }

    // =========================================================================
    // Batch Item Creation
    // =========================================================================

    private function handleClearCache(): bool
    {
        $cleared = $this->translationCacheService->clearCache();

        if ($cleared) {
            $this->showSuccess('Cache cleared successfully', 'Translation cache has been emptied.');
        } else {
            $this->showError('Failed to clear cache', 'Translation cache could not be cleared.');
        }

        return true;
    }

    private function showError(string $title, string $message): void
    {
        $this->addFlashMessage($title, $message, ContextualFeedbackSeverity::ERROR);
    }

    private function handleDelete(): bool
    {
        $items = $this->getBatchItemsFromArgument('delete');

        foreach ($items as $item) {
            $this->batchItemRepository->remove($item);
            $this->showSuccess('Successfully deleted', sprintf('Item with uid %d was deleted.', $item->getUid()));
        }

        return count($items) > 0;
    }

    // =========================================================================
    // Flash Messages
    // =========================================================================

    /**
     * @return list<BatchItem>
     */
    private function getBatchItemsFromArgument(string $argument): array
    {
        if (!$this->hasMutationParam($argument)) {
            return [];
        }

        $argumentValue = $this->getMutationParam($argument);
        $uids = is_array($argumentValue)
            ? $argumentValue
            : GeneralUtility::trimExplode(',', (string)$argumentValue, true);

        $items = array_filter(
            array_map(
                fn($uid) => $this->batchItemRepository->findByUid((int)$uid),
                $uids
            ),
            fn($item) => $item instanceof BatchItem
        );

        $accessibleItems = [];
        foreach ($items as $item) {
            if ($this->isBatchItemAccessible($item)) {
                $accessibleItems[] = $item;
                continue;
            }

            $this->showWarning(
                'Access denied',
                sprintf('You do not have permission to modify batch item %d.', $item->getUid())
            );
        }

        return $accessibleItems;
    }

    private function handleExecute(): void
    {
        try {
            $items = $this->getBatchItemsFromArgument('execute');

            foreach ($items as $item) {
                if (!$item->isExecutable()) {
                    $this->showError(
                        'Item cannot be translated',
                        sprintf('Item with uid %d could not be translated. Check the error and reset it.', $item->getUid())
                    );
                    continue;
                }

                $success = $this->batchTranslationService->translate($item);

                if ($success) {
                    $item->markAsTranslated();
                    $this->showSuccess('Successfully translated', sprintf('Item with uid %d was translated.', $item->getUid()));
                } else {
                    $errorDetail = $item->getError() ?: 'Unknown error';
                    $this->showError(
                        'Error while translating',
                        sprintf('Item with uid %d could not be translated: %s', $item->getUid(), $errorDetail)
                    );
                }

                $this->batchItemRepository->update($item);
            }
        } catch (Exception $e) {
            $this->showError('Error during translation', 'An error occurred: ' . $e->getMessage());
        }
    }

    private function handleReset(): void
    {
        $items = $this->getBatchItemsFromArgument('reset');

        foreach ($items as $item) {
            $item->setTranslated(null);
            $item->setError('');
            $this->batchItemRepository->update($item);
            $this->showSuccess('Reset successful', sprintf('Item with uid %d was reset.', $item->getUid()));
        }
    }

    protected function showCacheInfo(): void
    {
        $stats = $this->translationCacheService->getCacheStatistics();

        if (!$stats['enabled']) {
            $this->showInfo('Translation Cache: Disabled', 'All translations will use the API directly.');
            return;
        }

        $info = sprintf('Entries: %d | Size: %s', $stats['entries'], $stats['size_formatted']);
        $this->showInfo('Translation Cache: Active', $info);
    }

    private function showInfo(string $title, string $message): void
    {
        $this->addFlashMessage($title, $message, ContextualFeedbackSeverity::INFO);
    }

    private function getSiteConfiguration(): ?\TYPO3\CMS\Core\Site\Entity\Site
    {
        try {
            $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);
            return $siteFinder->getSiteByPageId($this->pageUid);
        } catch (Exception) {
            $this->showWarning(
                'No site configuration found',
                'Please select a configured page or create a new site configuration.'
            );
            return null;
        }
    }

    private function showWarning(string $title, string $message): void
    {
        $this->addFlashMessage($title, $message, ContextualFeedbackSeverity::WARNING);
    }

    // =========================================================================
    // Navigation
    // =========================================================================

    /**
     * @return array<int, \TYPO3\CMS\Core\Site\Entity\SiteLanguage>
     */
    private function getAccessibleLanguages(\TYPO3\CMS\Core\Site\Entity\Site $site): array
    {
        $languages = TranslationHelper::possibleTranslationLanguages($site->getLanguages());
        $backendUser = $this->getBackendUser();

        $filtered = array_filter(
            $languages,
            fn($lang) => $backendUser->checkLanguageAccess($lang->getLanguageId())
        );

        if (empty($filtered)) {
            $this->showWarning('No target language available', 'Please choose another page or contact the administrator.');
        }

        return $filtered;
    }

    /**
     * @param array<int, \TYPO3\CMS\Core\Site\Entity\SiteLanguage> $languages
     * @return array<int, BatchItem>
     */
    private function getAccessibleBatchItems(array $languages): array
    {
        $items = $this->batchItemRepository->findAllRecursive($this->levels, $this->pageUid);

        if ($items === null) {
            return [];
        }

        return array_filter(
            $items->toArray(),
            fn(BatchItem $item) => $this->isBatchItemAccessible($item, $languages)
        );
    }

    /**
     * @param array<int, \TYPO3\CMS\Core\Site\Entity\SiteLanguage>|null $languages
     */
    private function isBatchItemAccessible(BatchItem $item, ?array $languages = null): bool
    {
        $pageRecord = BackendUtility::getRecordWSOL('pages', $item->getPid());
        if ($pageRecord === null) {
            return false;
        }

        $backendUser = $this->getBackendUser();
        if (!$backendUser->doesUserHaveAccess($pageRecord, Permission::CONTENT_EDIT)) {
            return false;
        }

        if (!$backendUser->checkLanguageAccess($item->getSysLanguageUid())) {
            return false;
        }

        if ($languages === null) {
            try {
                $site = GeneralUtility::makeInstance(SiteFinder::class)->getSiteByPageId($item->getPid());
                $languages = TranslationHelper::possibleTranslationLanguages($site->getLanguages());
            } catch (Exception) {
                return false;
            }
        }

        return isset($languages[$item->getSysLanguageUid()]);
    }

    /**
     * Verify the current backend user may queue a translation for the given
     * page and target language. Mirrors isBatchItemAccessible() and guards the
     * creation path against crafted requests bypassing the form's offered values.
     */
    protected function userCanCreateForPage(int $pid, int $sysLanguageUid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        $pageRecord = BackendUtility::getRecordWSOL('pages', $pid);
        if (!is_array($pageRecord)) {
            return false;
        }

        $backendUser = $this->getBackendUser();

        return $backendUser->doesUserHaveAccess($pageRecord, Permission::CONTENT_EDIT)
            && $backendUser->checkLanguageAccess($sysLanguageUid);
    }

    protected function isPostRequest(): bool
    {
        return strtoupper($this->request->getMethod()) === 'POST';
    }

    private function hasMutationParam(string $name): bool
    {
        if ($this->request->hasArgument($name)) {
            return true;
        }

        return isset($this->queryParams[$name]) && $this->queryParams[$name] !== '';
    }

    private function getMutationParam(string $name): mixed
    {
        if ($this->request->hasArgument($name)) {
            return $this->request->getArgument($name);
        }

        return $this->queryParams[$name] ?? null;
    }

    private function hasMutationArguments(): bool
    {
        foreach (self::MUTATION_ARGUMENTS as $argument) {
            if ($this->hasMutationParam($argument)) {
                return true;
            }
        }

        return false;
    }

    private function warnIfGetMutationAttempt(): bool
    {
        if ($this->isPostRequest() || !$this->hasMutationArguments()) {
            return false;
        }

        $this->showWarning(
            'Action not permitted',
            'This action must be submitted via POST. Please use the module buttons or forms.'
        );

        return true;
    }

    /**
     * @param array<int, \TYPO3\CMS\Core\Site\Entity\SiteLanguage> $languages
     * @param array<string, mixed>|null $pageRecord
     * @return array<string, mixed>
     */
    private function buildCreateFormData(array $languages, ?array $pageRecord): array
    {
        $backendUser = $this->getBackendUser();
        $batchItem = null;

        if ($pageRecord && $backendUser->doesUserHaveAccess($pageRecord, Permission::CONTENT_EDIT)) {
            $batchItem = new BatchItem();
            $batchItem->setPid($this->pageUid);
            $batchItem->setTranslate(new DateTime());
        } else {
            $this->showWarning('No translations available', 'Please choose another page or contact the administrator.');
        }

        return [
            'pages' => $batchItem ? [$batchItem->getPid() => $batchItem->getPageTitle()] : null,
            'recursive' => $this->translateMenuLevelItems(),
            'priority' => $this->translatePriorityOptions(),
            'targetLanguage' => array_map(fn($lang) => $lang->getTitle(), $languages),
            'mode' => $this->translateModeOptions(),
            'frequency' => $this->translateFrequencyOptions(),
            'redirectAction' => $this->request->getControllerActionName(),
            'batchItem' => $batchItem,
        ];
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    /**
     * @return array<int, string>
     */
    private function translateMenuLevelItems(): array
    {
        $lang = $this->getLanguageService();
        $result = [];

        foreach (self::MENU_LEVEL_ITEMS as $level) {
            $result[$level] = $lang->sL("LLL:EXT:autotranslate/Resources/Private/Language/locallang_mod.xlf:mlang_labels_menu_level.{$level}");
        }

        return $result;
    }

    protected function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }

    /**
     * @return array<string, string>
     */
    private function translatePriorityOptions(): array
    {
        return $this->translateOptions([
            BatchItem::PRIORITY_LOW,
            BatchItem::PRIORITY_MEDIUM,
            BatchItem::PRIORITY_HIGH,
        ], 'autotranslate_batch.priority.');
    }

    /**
     * @param list<string> $keys
     * @return array<string, string>
     */
    private function translateOptions(array $keys, string $prefix): array
    {
        $lang = $this->getLanguageService();
        $result = [];

        foreach ($keys as $key) {
            $result[$key] = $lang->sL("LLL:EXT:autotranslate/Resources/Private/Language/locallang_db.xlf:{$prefix}{$key}");
        }

        return $result;
    }

    /**
     * @return array<string, string>
     */
    private function translateModeOptions(): array
    {
        return $this->translateOptions([
            Translator::TRANSLATE_MODE_BOTH,
            Translator::TRANSLATE_MODE_UPDATE_ONLY,
            Translator::TRANSLATE_MODE_CREATE_ONLY,
        ], 'autotranslate_batch.mode.');
    }

    /**
     * @return array<string, string>
     */
    private function translateFrequencyOptions(): array
    {
        return $this->translateOptions([
            BatchItem::FREQUENCY_ONCE,
            BatchItem::FREQUENCY_WEEKLY,
            BatchItem::FREQUENCY_DAILY,
            BatchItem::FREQUENCY_RECURRING,
        ], 'autotranslate_batch.frequency.');
    }

    protected function initializeAction(): void
    {
        $parsedBody = $this->request->getParsedBody();
        $this->queryParams = array_replace(
            $this->request->getQueryParams(),
            is_array($parsedBody) ? $parsedBody : []
        );

        $this->pageUid = (int)($this->queryParams['id'] ?? 0);
        $this->levels = $this->loadLevelsFromSession();

        if (isset($this->queryParams['levels'])) {
            $this->levels = (int)$this->queryParams['levels'];
            $this->saveLevelsToSession($this->levels);
        }

        parent::initializeAction();
    }

    private function loadLevelsFromSession(): int
    {
        return $this->getBackendUser()->getSessionData('autotranslate.levels') ?? 0;
    }

    protected function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }

    private function saveLevelsToSession(int $levels): void
    {
        $this->getBackendUser()->setAndSaveSessionData('autotranslate.levels', $levels);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function getCommonTemplateVariables(array $data = []): array
    {
        $cacheStats = $this->translationCacheService->getCacheStatistics();

        return array_merge($data, [
            'cacheEnabled' => $cacheStats['enabled'],
            'cacheStats' => $cacheStats,
            'pageUid' => $this->pageUid,
            'moduleName' => $this->moduleName,
            'schedulerStatus' => $this->getSchedulerStatus(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function getSchedulerStatus(): array
    {
        $runner = GeneralUtility::makeInstance(BatchTranslationRunner::class);
        $lastRun = $runner->getLastRunStatistics();

        if ($lastRun === null) {
            return ['hasRun' => false];
        }

        $lastRunTime = $lastRun['timestamp'] ?? 0;
        $ago = time() - $lastRunTime;

        return [
            'hasRun' => true,
            'timestamp' => $lastRunTime,
            'dateFormatted' => date('d.m.Y H:i:s', $lastRunTime),
            'agoMinutes' => (int)floor($ago / 60),
            'processed' => $lastRun['processed'] ?? 0,
            'succeeded' => $lastRun['succeeded'] ?? 0,
            'failed' => $lastRun['failed'] ?? 0,
            'remainingPending' => $lastRun['remainingPending'] ?? 0,
        ];
    }

    protected function createActionAbstract(BatchItem $batchItem, int $levels): void
    {
        if (!$this->userCanCreateForPage($batchItem->getPid(), $batchItem->getSysLanguageUid())) {
            $this->showWarning(
                'Access denied',
                sprintf(
                    'You do not have permission to queue translations for page %d in the selected language.',
                    $batchItem->getPid()
                )
            );
            return;
        }

        $this->adjustTimezoneOffset($batchItem);

        $createdCount = 0;
        $skippedCount = 0;
        $errorDetails = [];

        // Check for errored items on the root page
        $this->collectErrorDetails($batchItem->getPid(), $batchItem->getSysLanguageUid(), $errorDetails);

        if ($this->batchItemRepository->hasPendingItem($batchItem->getPid(), $batchItem->getSysLanguageUid())) {
            $skippedCount++;
        } else {
            $this->batchItemRepository->add($batchItem);
            $createdCount++;
        }

        $subResult = $this->createSubpageItems($batchItem, $levels, $errorDetails);
        $createdCount += $subResult['created'];
        $skippedCount += $subResult['skipped'];

        if ($createdCount > 0) {
            $this->showSuccess(
                'Queue items created',
                sprintf('%d item(s) created for page with uid %d.', $createdCount, $this->pageUid)
            );
        }

        if ($skippedCount > 0) {
            $this->showInfo(
                'Duplicates skipped',
                sprintf('%d item(s) skipped because pending items already exist.', $skippedCount)
            );
        }

        if ($createdCount === 0 && $skippedCount === 0) {
            $this->showWarning(
                'No items created',
                'No translatable pages found.'
            );
        }

        if (!empty($errorDetails)) {
            $this->showErrorSummary($errorDetails);
        }
    }

    private function adjustTimezoneOffset(BatchItem $batchItem): void
    {
        $context = GeneralUtility::makeInstance(Context::class);
        $timezone = new DateTimeZone($context->getPropertyFromAspect('date', 'timezone'));
        $offset = $timezone->getOffset(new DateTime('now'));

        if ($offset !== 0) {
            $translateTime = $batchItem->getTranslate();
            $translateTime->modify("-{$offset} seconds");
            $batchItem->setTranslate($translateTime);
        }
    }

    /**
     * @param array<int, array{pid: int, pageTitle: string, error: string}> $errorDetails Collected by reference
     * @return array{created: int, skipped: int}
     */
    private function createSubpageItems(BatchItem $batchItem, int $levels, array &$errorDetails): array
    {
        if ($levels <= 0) {
            return ['created' => 0, 'skipped' => 0];
        }

        $created = 0;
        $skipped = 0;
        $subPages = PageUtility::getSubpageIds($this->pageUid, $levels - 1);

        foreach ($subPages as $subPageUid) {
            $subPageUid = (int)$subPageUid;

            if (!$this->userCanCreateForPage($subPageUid, $batchItem->getSysLanguageUid())) {
                continue;
            }

            // Check for errored items on this subpage
            $this->collectErrorDetails($subPageUid, $batchItem->getSysLanguageUid(), $errorDetails);

            if ($this->batchItemRepository->hasPendingItem($subPageUid, $batchItem->getSysLanguageUid())) {
                $skipped++;
                continue;
            }

            $newItem = clone $batchItem;
            $newItem->setPid($subPageUid);
            $this->batchItemRepository->add($newItem);
            $created++;
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * Collect error details for a given page and language into the referenced array.
     *
     * @param array<int, array{pid: int, pageTitle: string, error: string}> $errorDetails
     */
    private function collectErrorDetails(int $pid, int $sysLanguageUid, array &$errorDetails): void
    {
        $erroredItems = $this->batchItemRepository->findErroredItems($pid, $sysLanguageUid);

        if (empty($erroredItems)) {
            return;
        }

        $pageRecord = BackendUtility::getRecordWSOL('pages', $pid);
        $pageTitle = trim(($pageRecord['title'] ?? 'Unknown') . ' [' . $pid . ']');

        foreach ($erroredItems as $item) {
            $errorDetails[] = [
                'pid' => $pid,
                'pageTitle' => $pageTitle,
                'error' => (string)($item['error'] ?? 'Unknown error'),
            ];
        }
    }

    /**
     * Display a summary flash message for all pages that have errored batch items.
     *
     * @param array<int, array{pid: int, pageTitle: string, error: string}> $errorDetails
     */
    private function showErrorSummary(array $errorDetails): void
    {
        // Group errors by page to avoid very long messages
        $byPage = [];
        foreach ($errorDetails as $detail) {
            $byPage[$detail['pid']][] = $detail;
        }

        $lines = [];
        foreach ($byPage as $pid => $items) {
            $pageTitle = $items[0]['pageTitle'];
            if (count($items) === 1) {
                $lines[] = sprintf('%s: %s', $pageTitle, $this->truncateError($items[0]['error']));
            } else {
                // Multiple errors on same page — show count and first error
                $lines[] = sprintf(
                    '%s: %d error(s), e.g. %s',
                    $pageTitle,
                    count($items),
                    $this->truncateError($items[0]['error'])
                );
            }
        }

        $pageCount = count($byPage);
        $totalErrors = count($errorDetails);
        $title = sprintf(
            'Existing errors found: %d item(s) on %d page(s)',
            $totalErrors,
            $pageCount
        );

        // Limit to 10 lines to keep the flash message readable
        $displayLines = array_slice($lines, 0, 10);
        if (count($lines) > 10) {
            $displayLines[] = sprintf('... and %d more page(s)', count($lines) - 10);
        }

        $this->showWarning($title, implode("\n", $displayLines));
    }

    /**
     * Truncate an error message for display in summary.
     */
    private function truncateError(string $error, int $maxLength = 120): string
    {
        if (mb_strlen($error) <= $maxLength) {
            return $error;
        }

        return mb_substr($error, 0, $maxLength) . '...';
    }

    protected function addDeeplApiKeyInfoMessage(): void
    {
        $apiKeyDetails = TranslationHelper::apiKey($this->pageUid);
        $apiKey = $apiKeyDetails['key'] ?? null;
        $this->deeplApiKeyDetails = DeeplApiHelper::checkApiKeyForDisplay($apiKey);

        $maskedKey = $this->maskApiKey($apiKey);
        $messages = [];
        $severity = ContextualFeedbackSeverity::INFO;

        if ($this->deeplApiKeyDetails['usageText']) {
            $usage = (string)$this->deeplApiKeyDetails['usageText'];
            $usage = str_replace([PHP_EOL, 'Characters: '], [' ', ''], $usage);
            $messages[] = trim($usage) . ' Characters';
        }

        if ($this->deeplApiKeyDetails['error']) {
            $messages[] = $this->deeplApiKeyDetails['error'];
            $severity = ContextualFeedbackSeverity::ERROR;
        }

        if (!empty($messages)) {
            $this->addFlashMessage('DeepL API Key: ' . $maskedKey, implode(PHP_EOL, $messages), $severity);
        }
    }

    private function maskApiKey(?string $apiKey): string
    {
        if (!$apiKey) {
            return '(not set)';
        }

        $length = strlen($apiKey);
        $visible = 4;

        if ($length <= $visible) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', $length - $visible) . substr($apiKey, -$visible);
    }
}
