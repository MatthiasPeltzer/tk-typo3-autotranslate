# Extension Settings

Configure the DeepL API key (fallback), supported tables, caching, and translation scope.

**After adding tables or upgrading to 3.0.4+, run the database compare in the Install Tool.**

![DeepL](../../Images/ExtensionConfiguration.png)

## Extension Settings

| Option | Description | Default |
|--------|-------------|---------|
| `additionalTables` | Comma-separated list of additional TCA tables | `''` |
| `additionalReferenceTables` | Comma-separated list of reference tables | `sys_file_reference` |
| `apiKey` | Fallback DeepL API key (site configuration is preferred) | `''` |
| `fieldsToCopy` | Fields copied into translated records without DeepL | `pi_flexform, hidden` |
| `debug` | Enable debug logging in the backend module | `0` |
| `caching` | Enable translation caching | `1` |
| `translateChangedFieldsOnly` | Skip unchanged translatable fields on save, batch, and scheduler | `1` |

### translateChangedFieldsOnly

When enabled (default):

- **Record updates (on save)**: Only changed configured text fields are sent to DeepL.
- **Batch / scheduler**: Uses per-field source hashes (`autotranslate_source_hash`) to detect changes without DataHandler context.
- **New records / first localization**: All configured fields are still translated.
- **Custom translations**: Fields marked `custom` in `l10n_state` are not overwritten unless the source field changed.

Disable this setting to always send all configured fields to DeepL (previous default behavior for batch runs).

## Example SQL Schema for Additional Tables

Except for `tx_news_domain_model_news`, you need to provide the SQL schema in your site package.

```sql
CREATE TABLE tx_table_name_item (
    autotranslate_exclude tinyint(4) DEFAULT '0' NOT NULL,
    autotranslate_languages varchar(255) DEFAULT NULL,
    autotranslate_last int(11) DEFAULT '0' NOT NULL,
    autotranslate_source_hash mediumtext
);
```

## Example SQL Schema for Reference Tables

Except for `sys_file_reference`, you need to provide the SQL schema in your site package.

```sql
CREATE TABLE tx_table_name_item (
    autotranslate_last int(11) DEFAULT '0' NOT NULL,
    autotranslate_exclude tinyint(4) DEFAULT '0' NOT NULL,
    autotranslate_source_hash mediumtext
);
```

The built-in `sys_file_reference` schema is provided by the extension and includes `autotranslate_last`, `autotranslate_exclude`, and `autotranslate_source_hash`.

> [!NOTE]
> If fields from third-party extensions that have `allowLanguageSynchronization` enabled (e.g., tt_address) need to be translated, clear the backend cache once after editing the site configuration.

> [!NOTE]
> After upgrading to 3.0.4, existing records have no source hashes yet. The first batch or scheduler run may translate all configured fields once; subsequent runs skip unchanged content.
