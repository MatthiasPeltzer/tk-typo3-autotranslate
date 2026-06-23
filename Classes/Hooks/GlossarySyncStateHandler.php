<?php

declare(strict_types=1);

namespace ThieleUndKlose\Autotranslate\Hooks;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Resets glossary sync state when editors change glossary records or entries.
 */
final class GlossarySyncStateHandler
{
    private const TABLE_GLOSSARY = 'tx_autotranslate_glossary';
    private const TABLE_ENTRY = 'tx_autotranslate_glossary_entry';

    /**
     * @param array<string, mixed> $fields
     */
    public function processDatamap_afterDatabaseOperations(
        string $status,
        string $table,
        int|string $recordUid,
        array $fields,
        DataHandler $dataHandler
    ): void {
        if ($table === self::TABLE_ENTRY) {
            $this->invalidateFromEntry($recordUid, $dataHandler);
            return;
        }

        if ($table !== self::TABLE_GLOSSARY || $status === 'delete') {
            return;
        }

        if (isset($dataHandler->substNEWwithIDs[$recordUid])) {
            $recordUid = $dataHandler->substNEWwithIDs[$recordUid];
        }

        $this->invalidateGlossarySyncState((int)$recordUid);
    }

    public function processCmdmap(
        string $command,
        string $table,
        int|string $id,
        mixed $value,
        bool $commandIsProcessed,
        DataHandler $dataHandler,
        mixed $pasteUpdate
    ): void {
        if ($command !== 'delete') {
            return;
        }

        if ($table === self::TABLE_ENTRY) {
            $this->invalidateFromEntry($id, $dataHandler);
        }
    }

    private function invalidateFromEntry(int|string $recordUid, DataHandler $dataHandler): void
    {
        if (isset($dataHandler->substNEWwithIDs[$recordUid])) {
            $recordUid = $dataHandler->substNEWwithIDs[$recordUid];
        }

        $glossaryUid = 0;
        if (isset($dataHandler->datamap[self::TABLE_ENTRY][$recordUid]['glossary'])) {
            $glossaryUid = (int)$dataHandler->datamap[self::TABLE_ENTRY][$recordUid]['glossary'];
        }

        if ($glossaryUid <= 0) {
            $record = BackendUtility::getRecord(self::TABLE_ENTRY, (int)$recordUid, 'glossary');
            $glossaryUid = (int)($record['glossary'] ?? 0);
        }

        if ($glossaryUid > 0) {
            $this->invalidateGlossarySyncState($glossaryUid);
        }
    }

    private function invalidateGlossarySyncState(int $glossaryUid): void
    {
        if ($glossaryUid <= 0) {
            return;
        }

        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable(self::TABLE_GLOSSARY)
            ->update(
                self::TABLE_GLOSSARY,
                [
                    'sync_ready' => 0,
                    'sync_error' => '',
                ],
                ['uid' => $glossaryUid]
            );
    }
}
