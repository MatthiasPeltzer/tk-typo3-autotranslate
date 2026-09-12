<?php

declare(strict_types=1);

namespace ThieleUndKlose\Autotranslate\Tests\Functional;

use PHPUnit\Framework\Attributes\Test;
use ThieleUndKlose\Autotranslate\Service\DeeplTranslationClient;
use ThieleUndKlose\Autotranslate\Service\GlossaryService;
use ThieleUndKlose\Autotranslate\Service\TranslationCacheService;
use ThieleUndKlose\Autotranslate\Tests\Functional\Fixtures\FakeDeeplTranslationClient;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Regression coverage for duplicated images in page translations.
 *
 * "pages.media" uses allowLanguageSynchronization, so TYPO3 localizes a newly added
 * file reference itself. For a brand new relation that localization initially carries
 * an unresolved "NEW..." pointer to its default language record, which DataHandler only
 * rewrites in processRemapStack(). Autotranslate must not attach a second reference in
 * that window.
 */
final class PageMediaReferenceTranslationTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['thieleundklose/autotranslate'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/pages.csv');
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $this->writeAutotranslateSite();

        GeneralUtility::setSingletonInstance(DeeplTranslationClient::class, new FakeDeeplTranslationClient(
            $this->get(TranslationCacheService::class),
            $this->get(GlossaryService::class),
        ));
    }

    #[Test]
    public function imageAddedToATranslatedPageIsLocalizedOnlyOnce(): void
    {
        $pageUid = $this->createDefaultPage('Team');
        $translatedPageUid = $this->fetchTranslatedPageUid($pageUid);
        self::assertGreaterThan(0, $translatedPageUid, 'the page translation was created');

        $fileUid = $this->createSysFile($this->createSysFileStorage());
        $this->addMediaReference($pageUid, $fileUid);

        self::assertCount(1, $this->fetchMediaReferences($pageUid, 0), 'the default page keeps one image');
        self::assertCount(
            1,
            $this->fetchMediaReferences($translatedPageUid, 1),
            'the translated page must not receive a second copy of the same image'
        );
    }

    private function writeAutotranslateSite(): void
    {
        $this->get(SiteWriter::class)->write('autotranslate-test', [
            'rootPageId' => 1,
            'base' => 'https://example.com/',
            'deeplAuthKey' => '00000000-0000-0000-0000-000000000000:fx',
            'autotranslatePagesEnabled' => true,
            'autotranslatePagesTextfields' => 'title,subtitle',
            'autotranslatePagesFileReferences' => 'media',
            'autotranslateSysFileReferenceTextfields' => 'title,alternative',
            'languages' => [
                [
                    'languageId' => 0,
                    'title' => 'English',
                    'locale' => 'en_US.UTF-8',
                    'base' => '/',
                    'flag' => 'us',
                    'navigationTitle' => 'English',
                    'deeplSourceLang' => 'EN',
                ],
                [
                    'languageId' => 1,
                    'title' => 'German',
                    'locale' => 'de_DE.UTF-8',
                    'base' => '/de/',
                    'flag' => 'de',
                    'navigationTitle' => 'Deutsch',
                    'fallbackType' => 'strict',
                    'fallbacks' => '',
                    'deeplTargetLang' => 'DE',
                ],
            ],
        ]);
    }

    private function createDefaultPage(string $title): int
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([
            'pages' => [
                'NEW1' => [
                    'pid' => 1,
                    'doktype' => 1,
                    'title' => $title,
                    'sys_language_uid' => 0,
                    'autotranslate_languages' => '1',
                ],
            ],
        ], []);
        $dataHandler->process_datamap();

        return (int)($dataHandler->substNEWwithIDs['NEW1'] ?? 0);
    }

    /**
     * Attach a brand new file reference the way FormEngine does: the parent field
     * points at the "NEW..." id of the child created in the very same run.
     */
    private function addMediaReference(int $pageUid, int $fileUid): void
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([
            'pages' => [
                $pageUid => ['media' => 'NEWmedia1'],
            ],
            'sys_file_reference' => [
                'NEWmedia1' => [
                    'pid' => $pageUid,
                    'uid_local' => $fileUid,
                    'uid_foreign' => $pageUid,
                    'tablenames' => 'pages',
                    'fieldname' => 'media',
                    'sys_language_uid' => 0,
                    'title' => 'Cat',
                    'alternative' => 'A cat',
                ],
            ],
        ], []);
        $dataHandler->process_datamap();
    }

    private function createSysFileStorage(): int
    {
        $configuration = '<?xml version="1.0" encoding="utf-8" standalone="yes" ?>'
            . '<T3FlexForms><data><sheet index="sDEF"><language index="lDEF">'
            . '<field index="basePath"><value index="vDEF">fileadmin/</value></field>'
            . '<field index="pathType"><value index="vDEF">relative</value></field>'
            . '</language></sheet></data></T3FlexForms>';

        $connection = $this->getConnectionPool()->getConnectionForTable('sys_file_storage');
        $connection->insert('sys_file_storage', [
            'pid' => 0,
            'name' => 'Test storage',
            'driver' => 'Local',
            'configuration' => $configuration,
            'is_online' => 1,
            'is_browsable' => 1,
            'is_public' => 1,
            'is_writable' => 1,
            'is_default' => 1,
        ]);

        return (int)$connection->lastInsertId();
    }

    private function createSysFile(int $storageUid): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('sys_file');
        $connection->insert('sys_file', [
            'pid' => 0,
            'storage' => $storageUid,
            'type' => 2,
            'identifier' => '/user_upload/cat.jpg',
            'identifier_hash' => sha1('/user_upload/cat.jpg'),
            'folder_hash' => sha1('/user_upload/'),
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'name' => 'cat.jpg',
            'sha1' => sha1('cat'),
            'size' => 1,
        ]);

        return (int)$connection->lastInsertId();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchMediaReferences(int $pageUid, int $languageId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->getConnectionPool()
            ->getConnectionForTable('sys_file_reference')
            ->executeQuery(
                'SELECT * FROM sys_file_reference'
                . " WHERE tablenames = 'pages' AND fieldname = 'media'"
                . ' AND uid_foreign = ? AND sys_language_uid = ? AND deleted = 0 ORDER BY uid',
                [$pageUid, $languageId]
            )
            ->fetchAllAssociative();

        return $rows;
    }

    private function fetchTranslatedPageUid(int $sourceUid): int
    {
        $uid = $this->getConnectionPool()
            ->getConnectionForTable('pages')
            ->executeQuery(
                'SELECT uid FROM pages WHERE sys_language_uid = 1 AND l10n_parent = ? AND deleted = 0',
                [$sourceUid]
            )
            ->fetchOne();

        return is_numeric($uid) ? (int)$uid : 0;
    }
}
