# Configuration

This section covers all configuration options for the Autotranslate extension.

## Configuration Areas

- [Extension Configuration](ExtensionConfiguration/Readme.md) - Global extension settings
- [Site Configuration](SiteConfigurations/Readme.md) - Per-site DeepL API and language settings
- [Languages](TranslatableElements/Languages.md) - Configuring translatable languages
- [Text Fields](TranslatableElements/Fields.md) - Defining which fields to translate

## Quick Configuration

### Minimal Site Configuration

Add to your `config/sites/<site>/config.yaml`:

```yaml
deeplAuthKey: 'your-deepl-api-key'
```

Enable tables, target languages, and text fields in the TYPO3 backend under **Sites > Edit Site > Autotranslate**. See [Site Configurations](SiteConfigurations/Readme.md) for details.

### Extension Configuration Options

Configure under **Admin Tools > Settings > Extension Configuration > autotranslate**:

| Option | Description | Default |
|--------|-------------|---------|
| `additionalTables` | Comma-separated list of additional TCA tables to support | `''` |
| `additionalReferenceTables` | Comma-separated list of reference tables (e.g. `sys_file_reference`) | `sys_file_reference` |
| `fieldsToCopy` | Fields copied into translated records without DeepL | `pi_flexform, hidden` |
| `debug` | Enable debug logging in the backend module | `0` |
| `caching` | Enable translation caching to reduce API calls | `1` |
| `translateChangedFieldsOnly` | On updates and batch/scheduler runs, send only changed translatable fields to DeepL. New records and first localizations still translate all configured fields. | `1` |

### Smart Translation (changed fields only)

When `translateChangedFieldsOnly` is enabled (default):

- **On save**: Only fields changed in the current DataHandler operation are sent to DeepL.
- **Batch / scheduler**: Only fields whose source content changed since the last translation are sent to DeepL (tracked via `autotranslate_source_hash` on source records).
- **First localization**: All configured fields are still translated for a new target language.
- **Manual edits**: Fields marked `custom` in `l10n_state` on an existing translation are skipped unless the corresponding source field changed.

See [Extension Configuration](ExtensionConfiguration/Readme.md) for SQL schema requirements when adding custom tables.
