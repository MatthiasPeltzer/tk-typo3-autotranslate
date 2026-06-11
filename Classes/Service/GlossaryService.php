<?php

declare(strict_types=1);

namespace ThieleUndKlose\Autotranslate\Service;

use DeepL\Translator;
use ThieleUndKlose\Autotranslate\Domain\Dto\Glossary;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class GlossaryService
{
    private const MODULE = 'autotranslate_glossary';
    private const TABLE = 'tx_autotranslate_glossary';

    /**
     * Get matching glossary for the given source/target language pair.
     */
    public function getGlossary(
        string $sourceLanguage,
        string $targetLanguage,
        int $pageId,
        Translator $translator
    ): ?Glossary {
        $glossaryIds = array_map(
            static fn($glossary) => $glossary->glossaryId,
            $translator->listGlossaries()
        );
        if ($glossaryIds === []) {
            return null;
        }

        $folderUids = $this->findGlossaryFolderUidsForPage($pageId);
        if ($folderUids === []) {
            return null;
        }

        $connectionPool = $this->getConnectionPool();
        $queryBuilder = $connectionPool->getQueryBuilderForTable(self::TABLE);
        $row = $queryBuilder
            ->select('uid', 'glossary_id')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->in(
                    'pid',
                    $queryBuilder->createNamedParameter($folderUids, Connection::PARAM_INT_ARRAY)
                ),
                $queryBuilder->expr()->eq(
                    'source_lang',
                    $queryBuilder->createNamedParameter($sourceLanguage)
                ),
                $queryBuilder->expr()->eq(
                    'target_lang',
                    $queryBuilder->createNamedParameter($targetLanguage)
                ),
                $queryBuilder->expr()->eq(
                    'sync_ready',
                    $queryBuilder->createNamedParameter(1, Connection::PARAM_INT)
                ),
                $queryBuilder->expr()->eq(
                    'hidden',
                    $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)
                ),
                $queryBuilder->expr()->in(
                    'glossary_id',
                    $queryBuilder->createNamedParameter($glossaryIds, Connection::PARAM_STR_ARRAY)
                )
            )
            ->orderBy('uid', 'ASC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? Glossary::fromDatabase($row) : null;
    }

    /**
     * @return list<int>
     */
    private function findGlossaryFolderUidsForPage(int $pageId): array
    {
        $connectionPool = $this->getConnectionPool();
        $db = $connectionPool->getQueryBuilderForTable('pages');
        $rows = $db
            ->select('uid')
            ->from('pages')
            ->where(
                $db->expr()->eq(
                    'doktype',
                    $db->createNamedParameter(PageRepository::DOKTYPE_SYSFOLDER, Connection::PARAM_INT)
                ),
                $db->expr()->eq('module', $db->createNamedParameter(self::MODULE))
            )
            ->executeQuery()
            ->fetchAllAssociative();

        if ($rows === []) {
            return [];
        }

        try {
            $rootPage = $this->findRootPageId($pageId);
        } catch (SiteNotFoundException) {
            return [];
        }

        $folderUids = [];
        foreach ($rows as $row) {
            try {
                if ($this->findRootPageId((int)$row['uid']) === $rootPage) {
                    $folderUids[] = (int)$row['uid'];
                }
            } catch (SiteNotFoundException) {
                continue;
            }
        }

        return $folderUids;
    }

    private function findRootPageId(int $pageId): int
    {
        return GeneralUtility::makeInstance(SiteFinder::class)->getSiteByPageId($pageId)->getRootPageId();
    }

    private function getConnectionPool(): ConnectionPool
    {
        return GeneralUtility::makeInstance(ConnectionPool::class);
    }
}
