<?php

declare(strict_types=1);

namespace ThieleUndKlose\Autotranslate\Log\Writer;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Log\LogLevel;
use TYPO3\CMS\Core\Log\LogRecord;
use TYPO3\CMS\Core\Log\Writer\AbstractWriter;
use TYPO3\CMS\Core\Log\Writer\WriterInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Writes extension log records into the dedicated `tx_autotranslate_log` table.
 *
 * This is a standalone {@see AbstractWriter} instead of a configured core
 * {@see \TYPO3\CMS\Core\Log\Writer\DatabaseWriter} with a `logTable` option,
 * because that option relies on `DatabaseWriter::setLogTable()` which is
 * deprecated since TYPO3 v14.2 and removed in v15. The field mapping mirrors
 * the core DatabaseWriter and works unchanged on TYPO3 v13.4 and v14.
 */
final class DatabaseTableWriter extends AbstractWriter
{
    private const LOG_TABLE = 'tx_autotranslate_log';

    public function writeLog(LogRecord $record): WriterInterface
    {
        try {
            // Avoid ConnectionPool usage prior to boot completion (see core #96291).
            if (!GeneralUtility::getContainer()->get('boot.state')->complete) {
                return $this;
            }
        } catch (\LogicException) {
            // Thrown while the container is not available yet.
            return $this;
        }

        $data = '';
        $context = $record->getData();
        if (!empty($context)) {
            // Fold an exception into a string so the context can be json-encoded.
            if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
                $context['exception'] = (string)$context['exception'];
            }
            $data = (string)json_encode($context);
        }

        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable(self::LOG_TABLE)
            ->insert(self::LOG_TABLE, [
                'request_id' => $record->getRequestId(),
                'time_micro' => $record->getCreated(),
                'component' => $record->getComponent(),
                'level' => LogLevel::normalizeLevel($record->getLevel()),
                'message' => $record->getMessage(),
                'data' => $data,
            ]);

        return $this;
    }
}
