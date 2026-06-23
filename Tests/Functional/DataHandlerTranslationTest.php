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
 * Integration coverage of the on-save auto-translation flow (DataHandler hook
 * -> Translator -> DeepL boundary), with the DeepL boundary replaced by a fake
 * so no network call is made.
 *
 * Requires a database and therefore runs in CI (functional job).
 */
final class DataHandlerTranslationTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['thieleundklose/autotranslate'];

    private FakeDeeplTranslationClient $fakeClient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/pages.csv');
        $backendUser = $this->setUpBackendUser(1);
        // DataHandler::localize() resolves $GLOBALS['LANG']; it is not set up by
        // setUpBackendUser(), so create it explicitly (as core's own DataHandler
        // functional tests do) to avoid a TypeError from getLanguageService().
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $this->writeAutotranslateSite();

        $this->fakeClient = new FakeDeeplTranslationClient(
            $this->get(TranslationCacheService::class),
            $this->get(GlossaryService::class),
        );
        GeneralUtility::setSingletonInstance(DeeplTranslationClient::class, $this->fakeClient);
    }

    #[Test]
    public function savingDefaultLanguageRecordCreatesTranslation(): void
    {
        $sourceUid = $this->createDefaultContent('Hello', 'World');

        $translation = $this->fetchTranslation($sourceUid);

        self::assertIsArray($translation, 'a German translation row was created');
        self::assertSame('DE:Hello', $translation['header']);
        self::assertSame('DE:World', $translation['bodytext']);
        self::assertSame(1, $this->fakeClient->translateCallCount);

        // Auto-translated fields are flagged "custom" so TYPO3 keeps them.
        self::assertNotEmpty($translation['l10n_state']);
        $state = json_decode((string)$translation['l10n_state'], true);
        self::assertIsArray($state);
        self::assertSame('custom', $state['header'] ?? null);
        self::assertSame('custom', $state['bodytext'] ?? null);
    }

    #[Test]
    public function updatingOnlyOneSourceFieldRetranslatesThatFieldOnly(): void
    {
        $sourceUid = $this->createDefaultContent('Hello', 'World');
        $created = $this->fetchTranslation($sourceUid);
        self::assertSame('DE:Hello', $created['header']);
        self::assertSame('DE:World', $created['bodytext']);

        $callsAfterCreate = $this->fakeClient->translateCallCount;

        $this->updateContent($sourceUid, ['bodytext' => 'World changed']);

        $updated = $this->fetchTranslation($sourceUid);
        self::assertSame('DE:Hello', $updated['header'], 'unchanged field must not be retranslated');
        self::assertSame('DE:World changed', $updated['bodytext']);
        self::assertGreaterThan($callsAfterCreate, $this->fakeClient->translateCallCount);
    }

    #[Test]
    public function manualCorrectionIsPreservedUntilSourceFieldChanges(): void
    {
        $sourceUid = $this->createDefaultContent('Hello', 'World');
        $translation = $this->fetchTranslation($sourceUid);
        $translationUid = (int)$translation['uid'];

        // An editor manually corrects the German header; l10n_state keeps it "custom".
        $this->setRawFields('tt_content', $translationUid, ['header' => 'Manuelle Korrektur']);

        // Changing only the source bodytext must not overwrite the manual header.
        $this->updateContent($sourceUid, ['bodytext' => 'World changed']);
        $afterBodyChange = $this->fetchTranslation($sourceUid);
        self::assertSame('Manuelle Korrektur', $afterBodyChange['header'], 'custom field preserved while source header unchanged');
        self::assertSame('DE:World changed', $afterBodyChange['bodytext']);

        // Changing the source header overwrites the manual correction.
        $this->updateContent($sourceUid, ['header' => 'Hello again']);
        $afterHeaderChange = $this->fetchTranslation($sourceUid);
        self::assertSame('DE:Hello again', $afterHeaderChange['header'], 'custom field overwritten when source field changes');
    }

    #[Test]
    public function recordWithoutTargetLanguagesIsNotTranslated(): void
    {
        $sourceUid = $this->createDefaultContent('Hello', 'World', '');

        self::assertNull($this->fetchTranslation($sourceUid), 'no translation without target languages');
        self::assertSame(0, $this->fakeClient->translateCallCount);
    }

    #[Test]
    public function recordMarkedExcludedIsNotTranslated(): void
    {
        $sourceUid = $this->createDefaultContent('Hello', 'World', '1', ['autotranslate_exclude' => 1]);

        self::assertNull($this->fetchTranslation($sourceUid), 'excluded record is skipped');
        self::assertSame(0, $this->fakeClient->translateCallCount);
    }

    private function writeAutotranslateSite(): void
    {
        $this->get(SiteWriter::class)->write('autotranslate-test', [
            'rootPageId' => 1,
            'base' => 'https://example.com/',
            'deeplAuthKey' => '00000000-0000-0000-0000-000000000000:fx',
            'autotranslateTtContentEnabled' => true,
            'autotranslateTtContentTextfields' => 'header,bodytext',
            // No site-wide default target language: records drive their own
            // targets via autotranslate_languages, so a record with none is
            // genuinely "without target languages".
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

    /**
     * @param array<string, mixed> $extraFields
     */
    private function createDefaultContent(string $header, string $bodytext, string $languages = '1', array $extraFields = []): int
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([
            'tt_content' => [
                'NEW1' => array_merge([
                    'pid' => 1,
                    'CType' => 'text',
                    'header' => $header,
                    'bodytext' => $bodytext,
                    'sys_language_uid' => 0,
                    'autotranslate_languages' => $languages,
                ], $extraFields),
            ],
        ], []);
        $dataHandler->process_datamap();

        return (int)$dataHandler->substNEWwithIDs['NEW1'];
    }

    /**
     * Update a default-language record through the DataHandler so the
     * autotranslate hook runs with status "update" and the changed fields.
     *
     * @param array<string, mixed> $fields
     */
    private function updateContent(int $uid, array $fields): void
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['tt_content' => [$uid => $fields]], []);
        $dataHandler->process_datamap();
    }

    /**
     * Write raw column values directly (bypassing the DataHandler/hook), used to
     * simulate a manual editor correction on a translation.
     *
     * @param array<string, mixed> $fields
     */
    private function setRawFields(string $table, int $uid, array $fields): void
    {
        $this->getConnectionPool()
            ->getConnectionForTable($table)
            ->update($table, $fields, ['uid' => $uid]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchTranslation(int $sourceUid): ?array
    {
        $row = $this->getConnectionPool()
            ->getConnectionForTable('tt_content')
            ->select(['*'], 'tt_content', ['sys_language_uid' => 1, 'l18n_parent' => $sourceUid])
            ->fetchAssociative();

        return $row === false ? null : $row;
    }
}
