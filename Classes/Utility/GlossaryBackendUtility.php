<?php

declare(strict_types=1);

namespace ThieleUndKlose\Autotranslate\Utility;

use Psr\Http\Message\ServerRequestInterface;
use ThieleUndKlose\Autotranslate\Service\GlossarySyncService;
use TYPO3\CMS\Backend\Context\PageContext;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class GlossaryBackendUtility
{
    private const TABLE_GLOSSARY = 'tx_autotranslate_glossary';

    public static function resolvePageId(ServerRequestInterface $request): int
    {
        $pageContext = $request->getAttribute('pageContext');
        if ($pageContext instanceof PageContext && $pageContext->pageId > 0) {
            return $pageContext->pageId;
        }

        return (int)($request->getQueryParams()['id'] ?? $request->getParsedBody()['id'] ?? 0);
    }

    public static function canUserSyncFolder(int $pageId): bool
    {
        if ($pageId <= 0 || !self::isGlossaryFolderPage($pageId)) {
            return false;
        }

        $backendUser = self::getBackendUser();
        if (!$backendUser->check('tables_modify', self::TABLE_GLOSSARY)) {
            return false;
        }

        $pageRecord = BackendUtility::readPageAccess(
            $pageId,
            $backendUser->getPagePermsClause(Permission::PAGE_SHOW)
        );
        if (!is_array($pageRecord)) {
            return false;
        }

        return $backendUser->doesUserHaveAccess($pageRecord, Permission::PAGE_EDIT);
    }

    public static function getSyncFormId(int $pageId): string
    {
        return 'autotranslate-glossary-sync-form-' . $pageId;
    }

    public static function buildSyncActionUrl(int $pageId): string
    {
        return (string)GeneralUtility::makeInstance(UriBuilder::class)->buildUriFromRoute(
            'autotranslate_glossary_sync',
            ['id' => $pageId]
        );
    }

    public static function isGlossaryFolderPage(int $pageId): bool
    {
        return GeneralUtility::makeInstance(GlossarySyncService::class)->isGlossaryFolder($pageId);
    }

    /**
     * @param non-empty-string $helpHtml Pre-escaped HTML
     */
    public static function renderSyncPanel(int $pageId, ServerRequestInterface $request, string $helpHtml = ''): string
    {
        if (!self::canUserSyncFolder($pageId)) {
            return '';
        }

        $help = $helpHtml !== ''
            ? $helpHtml
            : '<p class="mb-0">' . htmlspecialchars(self::translate('glossary.sync.help'), ENT_QUOTES | ENT_HTML5) . '</p>';
        $formMarkup = self::renderSyncFormMarkup($pageId, $request);

        return <<<HTML
<div class="callout callout-info mb-3">
    <div class="callout-content">
        {$help}
        <div class="mt-2">
            {$formMarkup}
        </div>
    </div>
</div>
HTML;
    }

    public static function renderSyncFormMarkup(int $pageId, ServerRequestInterface $request): string
    {
        $formId = self::getSyncFormId($pageId);
        $actionUrl = htmlspecialchars(self::buildSyncActionUrl($pageId), ENT_QUOTES | ENT_HTML5);
        $returnUrl = htmlspecialchars(
            GeneralUtility::sanitizeLocalUrl((string)$request->getUri(), $request),
            ENT_QUOTES | ENT_HTML5
        );
        $label = htmlspecialchars(self::translate('glossary.sync.button'), ENT_QUOTES | ENT_HTML5);
        $submitButton = <<<HTML
<button type="submit" class="btn btn-default">
    <span class="icon icon-size-small icon-state-default">
        <span class="icon-markup"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16"><g fill="currentColor"><path d="M13.5 2.5A7 7 0 1 0 14 8h-1.5a5.5 5.5 0 1 1 1-4.9V6H14l-3 3-3-3h1.5V2.5z"/></g></svg></span>
    </span>
    {$label}
</button>
HTML;

        return <<<HTML
<form id="{$formId}" method="post" action="{$actionUrl}">
    <input type="hidden" name="id" value="{$pageId}">
    <input type="hidden" name="returnUrl" value="{$returnUrl}">
    {$submitButton}
</form>
HTML;
    }

    public static function translate(string $key): string
    {
        $label = $GLOBALS['LANG']->sL('LLL:EXT:autotranslate/Resources/Private/Language/locallang.xlf:' . $key);

        return is_string($label) && $label !== '' ? $label : $key;
    }

    private static function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}
