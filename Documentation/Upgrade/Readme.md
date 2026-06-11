# Upgrade Instructions

## Upgrading to Version 3.0.4

### Database

Run the **database analyzer** (Install Tool or CLI) to add `autotranslate_source_hash` to:

- `pages`
- `tt_content`
- `tx_news_domain_model_news`
- `sys_file_reference`

Custom tables from `additionalTables` / `additionalReferenceTables` need the same column in your site package schema if you manage them yourself.

### Behavior

- Batch and scheduler runs now respect `translateChangedFieldsOnly` using per-field source hashes.
- Existing records have no hashes until the first translation after upgrade. Expect **one full pass** per record/language before unchanged fields are skipped.
- Clear caches after the update.

---

## Upgrading to Version 3.0.3

No configuration changes required.

- Existing translations with `l10n_state` custom fields are preserved unless the source field changes.
- Direct saves on reference records (e.g. FAL alt/title) can trigger translation when the parent reference column is configured.

---

## Upgrading to Version 3.0.2

No configuration changes required.

- New extension setting `translateChangedFieldsOnly` (enabled by default). On record **updates**, only changed translatable fields are sent to DeepL.
- New records and first localizations still translate all configured fields.

---

## Upgrading to Version 3.0.1

No configuration changes required.

- Batch and log mutations (execute, delete, reset, clear cache) require **POST** requests.
- Batch execute/delete/reset checks **page and language access** per item.

---

## Upgrading to Version 3.0.0

Version 3.0.0 introduces breaking changes. Please read carefully before upgrading.

### Requirements Changed

- **TYPO3**: Now requires 13.4 or 14.x (dropped support for 11 and 12)
- **PHP**: Now requires 8.2 - 8.5

### Breaking Changes

1. **Removed Legacy Controllers**: The `BatchTranslationLegacyController` has been removed
2. **Removed Legacy Templates**: `DefaultLegacy.html` and `ShowLogsLegacy.html` are no longer needed
3. **TCA Format**: All TCA configurations now use the new TYPO3 13+ format

### New Features in 3.0

- "Create only" translation mode (only creates missing translations)
- Dedicated scheduler task with visual progress bar
- Duplicate batch item prevention
- Error reporting when creating batch items
- German backend translations
- Codebase modernized with `final`, `readonly`, arrow functions, strict types

### Upgrade Steps

1. **Check Requirements**: Ensure your environment meets the new requirements
2. **Update via Composer**:
   ```bash
   composer require thieleundklose/autotranslate:^3.0
   ```
3. **Clear Caches**: Flush all caches after the update
4. **Database Update**: Run the database analyzer to apply schema changes
5. **Scheduler**: If using the scheduler, add the new "Autotranslate Batch Translation" task for progress bar support. The old console command task still works but does not display progress.

### Configuration Migration

No configuration changes are required. All existing site configurations remain compatible.

---

## Upgrading to Version 2.0.0

### New Features

- Batch translation support for non-admin users
- Improved backend module

### Upgrade Steps

1. Update via Composer or Extension Manager
2. Clear all caches
3. Review user permissions for the new batch translation features

---

## Upgrading to Version 1.3.0

If you have already configured news content translation from `tx_news`, you need to manually add the table to the extension configuration.

See [Extension Configuration](../Configuration/ExtensionConfiguration/Readme.md) for details.
