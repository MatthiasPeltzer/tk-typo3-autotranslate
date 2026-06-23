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

### `l10n_state = custom` tradeoff (manual corrections)

Auto-translated target fields are tagged in the translation's `l10n_state` so the
extension knows which fields it owns. When an editor manually corrects a target
field in the backend, TYPO3 flips that field's `l10n_state` to `custom`, and
Autotranslate then leaves it alone on subsequent runs — **as long as the source
field does not change**.

Be aware of the inherent limitation:

- A `custom` field is only protected while its source stays the same. **If the
  corresponding source field changes, the field is re-translated and the manual
  correction is overwritten** (this is required so genuine source edits propagate).
- An auto-translated value and a manually-edited value are indistinguishable at
  the data level once both exist; the `custom` flag is the only signal. If that
  flag is lost (e.g. a field is reset, or `l10n_state` is cleared by another
  tool), the next translation run will overwrite the value.

Practical guidance: make lasting wording corrections in the **source** record (or
via a DeepL glossary) rather than only in the target, so the change survives the
next translation. Reserve target-only edits for cases where the source must stay
untouched, and re-apply them if you later edit that source field.

See [Extension Configuration](ExtensionConfiguration/Readme.md) for SQL schema requirements when adding custom tables.
