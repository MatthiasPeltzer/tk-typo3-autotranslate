<?php

declare(strict_types=1);

namespace ThieleUndKlose\Autotranslate\Tests\Functional;

use PHPUnit\Framework\Attributes\Test;
use ThieleUndKlose\Autotranslate\Service\DeeplTranslationClient;
use ThieleUndKlose\Autotranslate\Service\GlossaryService;
use ThieleUndKlose\Autotranslate\Service\TranslationCacheService;
use ThieleUndKlose\Autotranslate\Tests\Functional\Fixtures\FakeDeeplTranslationClient;
use ThieleUndKlose\Autotranslate\Utility\Translator;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Integration coverage of the reference-record translation flow
 * (Translator::translateReferenceRecord) for a directly saved
 * sys_file_reference, with the DeepL boundary faked (no network call).
 */
final class ReferenceTranslationTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['thieleundklose/autotranslate'];

    private FakeDeeplTranslationClient $fakeClient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/pages.csv');
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $this->writeAutotranslateSite();

        $this->fakeClient = new FakeDeeplTranslationClient(
            $this->get(TranslationCacheService::class),
            $this->get(GlossaryService::class),
        );
        GeneralUtility::setSingletonInstance(DeeplTranslationClient::class, $this->fakeClient);
    }

    #[Test]
    public function directlySavedFileReferenceIsTranslated(): void
    {
        // A translated parent content element must already exist, since the
        // reference translation attaches the localized reference to it.
        $parentUid = $this->createParentContentWithTranslation();

        $storageUid = $this->createSysFileStorage();
        $fileUid = $this->createSysFile($storageUid);
        $referenceUid = $this->createFileReference($parentUid, $fileUid, [
            'title' => 'Cat',
            'alternative' => 'A cat',
        ]);

        $translator = GeneralUtility::makeInstance(Translator::class, 1);
        $translator->translateReferenceRecord('sys_file_reference', $referenceUid, null, 'new', null);

        $translatedReference = $this->fetchTranslatedReference($referenceUid);

        self::assertIsArray($translatedReference, 'a localized sys_file_reference was created');
        self::assertSame('DE:Cat', $translatedReference['title']);
        self::assertSame('DE:A cat', $translatedReference['alternative']);

        $localizedParentUid = (int)($this->fetchTranslatedContent($parentUid)['uid'] ?? 0);
        self::assertGreaterThan(0, $localizedParentUid);
        self::assertSame(
            $localizedParentUid,
            (int)$translatedReference['uid_foreign'],
            'the localized reference points at the localized parent'
        );
    }

    private function writeAutotranslateSite(): void
    {
        $this->get(SiteWriter::class)->write('autotranslate-test', [
            'rootPageId' => 1,
            'base' => 'https://example.com/',
            'deeplAuthKey' => '00000000-0000-0000-0000-000000000000:fx',
            'autotranslateTtContentEnabled' => true,
            'autotranslateTtContentTextfields' => 'header,bodytext',
            'autotranslateTtContentFileReferences' => 'image',
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

    private function createParentContentWithTranslation(): int
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([
            'tt_content' => [
                'NEW1' => [
                    'pid' => 1,
                    'CType' => 'text',
                    'header' => 'Hello',
                    'bodytext' => 'World',
                    'sys_language_uid' => 0,
                    'autotranslate_languages' => '1',
                ],
            ],
        ], []);
        $dataHandler->process_datamap();

        return (int)$dataHandler->substNEWwithIDs['NEW1'];
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
     * @param array<string, mixed> $fields
     */
    private function createFileReference(int $parentUid, int $fileUid, array $fields): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('sys_file_reference');
        $connection->insert('sys_file_reference', array_merge([
            'pid' => 1,
            'sys_language_uid' => 0,
            'l10n_parent' => 0,
            'uid_local' => $fileUid,
            'uid_foreign' => $parentUid,
            'tablenames' => 'tt_content',
            'fieldname' => 'image',
        ], $fields));

        return (int)$connection->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchTranslatedReference(int $sourceUid): ?array
    {
        $row = $this->getConnectionPool()
            ->getConnectionForTable('sys_file_reference')
            ->select(['*'], 'sys_file_reference', ['sys_language_uid' => 1, 'l10n_parent' => $sourceUid])
            ->fetchAssociative();

        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchTranslatedContent(int $sourceUid): ?array
    {
        $row = $this->getConnectionPool()
            ->getConnectionForTable('tt_content')
            ->select(['*'], 'tt_content', ['sys_language_uid' => 1, 'l18n_parent' => $sourceUid])
            ->fetchAssociative();

        return $row === false ? null : $row;
    }
}
