<?php

declare(strict_types=1);

namespace ThieleUndKlose\Autotranslate\EventListener;

use ThieleUndKlose\Autotranslate\Utility\GlossaryBackendUtility;
use TYPO3\CMS\Backend\Controller\Event\ModifyPageLayoutContentEvent;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final readonly class GlossaryPageLayoutListener
{
    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function __invoke(ModifyPageLayoutContentEvent $event): void
    {
        $request = $event->getRequest();
        $pageId = GlossaryBackendUtility::resolvePageId($request);
        if ($pageId <= 0 || !GlossaryBackendUtility::canUserSyncFolder($pageId)) {
            return;
        }

        $event->addHeaderContent(
            GlossaryBackendUtility::renderSyncPanel($pageId, $request, $this->buildSummaryHtml($pageId))
        );
    }

    private function buildSummaryHtml(int $pageId): string
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_autotranslate_glossary');
        $rows = $queryBuilder
            ->select('uid', 'source_lang', 'target_lang', 'sync_ready', 'last_sync', 'sync_error')
            ->from('tx_autotranslate_glossary')
            ->where(
                $queryBuilder->expr()->eq(
                    'pid',
                    $queryBuilder->createNamedParameter($pageId, Connection::PARAM_INT)
                ),
                $queryBuilder->expr()->eq(
                    'hidden',
                    $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)
                )
            )
            ->orderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();

        if ($rows === []) {
            return '<p class="mb-0">' . htmlspecialchars(GlossaryBackendUtility::translate('glossary.sync.no_records')) . '</p>';
        }

        $items = [];
        foreach ($rows as $row) {
            $status = (int)($row['sync_ready'] ?? 0) === 1
                ? GlossaryBackendUtility::translate('glossary.sync.status.ready')
                : GlossaryBackendUtility::translate('glossary.sync.status.pending');
            $lastSync = (int)($row['last_sync'] ?? 0);
            $lastSyncLabel = $lastSync > 0
                ? date('Y-m-d H:i', $lastSync)
                : GlossaryBackendUtility::translate('glossary.sync.never');
            $line = sprintf(
                '%s → %s: %s (%s: %s)',
                (string)$row['source_lang'],
                (string)$row['target_lang'],
                $status,
                GlossaryBackendUtility::translate('glossary.sync.last_sync'),
                $lastSyncLabel
            );
            if ((int)($row['sync_ready'] ?? 0) !== 1 && !empty($row['sync_error'])) {
                $line .= ' — ' . (string)$row['sync_error'];
            }
            $items[] = '<li>' . htmlspecialchars($line) . '</li>';
        }

        return '<p class="mb-0">' . htmlspecialchars(GlossaryBackendUtility::translate('glossary.sync.help')) . '</p><ul class="mb-0">' . implode('', $items) . '</ul>';
    }
}
