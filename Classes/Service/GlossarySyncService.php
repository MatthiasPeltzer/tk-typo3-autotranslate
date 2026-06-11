<?php

declare(strict_types=1);

namespace ThieleUndKlose\Autotranslate\Service;

use DeepL\DeepLException;
use DeepL\GlossaryEntries;
use DeepL\GlossaryNotFoundException;
use DeepL\Translator;
use ThieleUndKlose\Autotranslate\Domain\Dto\GlossarySyncResult;
use ThieleUndKlose\Autotranslate\Utility\TranslationHelper;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class GlossarySyncService
{
    private const TABLE_GLOSSARY = 'tx_autotranslate_glossary';
    private const TABLE_ENTRY = 'tx_autotranslate_glossary_entry';

    /**
     * @return list<GlossarySyncResult>
     */
    public function syncFolder(int $folderPageId): array
    {
        if (!$this->isGlossaryFolder($folderPageId)) {
            return [
                new GlossarySyncResult(
                    glossaryUid: 0,
                    success: false,
                    message: 'The selected page is not an Autotranslate glossary folder.',
                ),
            ];
        }

        $glossaryUids = $this->findGlossaryUidsInFolder($folderPageId);
        if ($glossaryUids === []) {
            return [
                new GlossarySyncResult(
                    glossaryUid: 0,
                    success: false,
                    message: 'No glossary records found in this folder.',
                ),
            ];
        }

        $results = [];
        foreach ($glossaryUids as $glossaryUid) {
            $results[] = $this->syncGlossary($glossaryUid, $folderPageId);
        }

        return $results;
    }

    public function syncGlossary(int $glossaryUid, int $folderPageId): GlossarySyncResult
    {
        $glossaryRecord = BackendUtility::getRecord(self::TABLE_GLOSSARY, $glossaryUid);
        if (!is_array($glossaryRecord)) {
            return new GlossarySyncResult(
                glossaryUid: $glossaryUid,
                success: false,
                message: 'Glossary record not found.',
            );
        }

        if ((int)$glossaryRecord['pid'] !== $folderPageId) {
            return new GlossarySyncResult(
                glossaryUid: $glossaryUid,
                success: false,
                message: 'Glossary record does not belong to the selected folder.',
            );
        }

        $sourceLang = trim((string)($glossaryRecord['source_lang'] ?? ''));
        $targetLang = trim((string)($glossaryRecord['target_lang'] ?? ''));
        if ($sourceLang === '' || $targetLang === '') {
            return $this->markFailure($glossaryUid, 'Source and target language must be configured.');
        }

        $entries = $this->loadEntries($glossaryUid);
        if ($entries === []) {
            return $this->markFailure($glossaryUid, 'At least one glossary entry is required.');
        }

        $apiKey = TranslationHelper::apiKey($folderPageId)['key'] ?? null;
        if ($apiKey === null || $apiKey === '') {
            return $this->markFailure($glossaryUid, 'No DeepL API key configured for this site.');
        }

        $folderTitle = (string)(BackendUtility::getRecord('pages', $folderPageId, 'title')['title'] ?? 'Glossary');
        $glossaryName = sprintf(
            'TYPO3 autotranslate: %s (%s-%s)',
            $folderTitle,
            $sourceLang,
            $targetLang
        );

        try {
            $translator = new Translator($apiKey);
            $existingGlossaryId = trim((string)($glossaryRecord['glossary_id'] ?? ''));
            if ($existingGlossaryId !== '') {
                try {
                    $translator->deleteGlossary($existingGlossaryId);
                } catch (GlossaryNotFoundException) {
                    // Already removed remotely — continue with recreation.
                }
            }

            $glossaryInfo = $translator->createGlossary(
                $glossaryName,
                $sourceLang,
                $targetLang,
                GlossaryEntries::fromEntries($entries)
            );

            $this->updateGlossaryRecord($glossaryUid, [
                'glossary_id' => $glossaryInfo->glossaryId,
                'last_sync' => time(),
                'sync_ready' => 1,
                'sync_error' => '',
            ]);

            return new GlossarySyncResult(
                glossaryUid: $glossaryUid,
                success: true,
                message: sprintf(
                    'Glossary synced (%s → %s, %d entries).',
                    $sourceLang,
                    $targetLang,
                    count($entries)
                ),
                glossaryId: $glossaryInfo->glossaryId,
            );
        } catch (DeepLException $exception) {
            return $this->markFailure($glossaryUid, $exception->getMessage());
        } catch (\Throwable $exception) {
            return $this->markFailure($glossaryUid, 'Unexpected error: ' . $exception->getMessage());
        }
    }

    public function isGlossaryFolder(int $pageId): bool
    {
        if ($pageId <= 0) {
            return false;
        }

        $pageRecord = BackendUtility::getRecord('pages', $pageId, 'module');
        if (!is_array($pageRecord)) {
            return false;
        }

        $module = $pageRecord['module'] ?? '';
        if (is_array($module)) {
            $module = (string)reset($module);
        }

        return (string)$module === 'autotranslate_glossary';
    }

    /**
     * @return list<int>
     */
    private function findGlossaryUidsInFolder(int $folderPageId): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable(self::TABLE_GLOSSARY);
        $rows = $queryBuilder
            ->select('uid')
            ->from(self::TABLE_GLOSSARY)
            ->where(
                $queryBuilder->expr()->eq(
                    'pid',
                    $queryBuilder->createNamedParameter($folderPageId, Connection::PARAM_INT)
                ),
                $queryBuilder->expr()->eq(
                    'hidden',
                    $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)
                )
            )
            ->orderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn(array $row): int => (int)$row['uid'], $rows);
    }

    /**
     * @return array<string, string>
     */
    private function loadEntries(int $glossaryUid): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable(self::TABLE_ENTRY);
        $rows = $queryBuilder
            ->select('source_term', 'target_term')
            ->from(self::TABLE_ENTRY)
            ->where(
                $queryBuilder->expr()->eq(
                    'glossary',
                    $queryBuilder->createNamedParameter($glossaryUid, Connection::PARAM_INT)
                ),
                $queryBuilder->expr()->eq(
                    'hidden',
                    $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)
                )
            )
            ->orderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();

        $entries = [];
        foreach ($rows as $row) {
            $source = trim((string)($row['source_term'] ?? ''));
            $target = trim((string)($row['target_term'] ?? ''));
            if ($source === '' || $target === '') {
                continue;
            }
            $entries[$source] = $target;
        }

        return $entries;
    }

    /**
     * @param array<string, scalar|null> $fields
     */
    private function updateGlossaryRecord(int $glossaryUid, array $fields): void
    {
        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable(self::TABLE_GLOSSARY)
            ->update(
                self::TABLE_GLOSSARY,
                $fields,
                ['uid' => $glossaryUid]
            );
    }

    private function markFailure(int $glossaryUid, string $message): GlossarySyncResult
    {
        $this->updateGlossaryRecord($glossaryUid, [
            'sync_ready' => 0,
            'sync_error' => $message,
        ]);

        return new GlossarySyncResult(
            glossaryUid: $glossaryUid,
            success: false,
            message: $message,
        );
    }

    private function getConnectionPool(): ConnectionPool
    {
        return GeneralUtility::makeInstance(ConnectionPool::class);
    }
}
