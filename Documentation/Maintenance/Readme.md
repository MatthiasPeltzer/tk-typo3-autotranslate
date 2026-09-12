# Maintenance

## Repairing translated reference records

Earlier versions could attach a second file reference to a translation when the
reference was added to the default language record in the same save. Such records
show the same image twice, and a translated reference whose default language record
was meanwhile deleted makes the backend fail with
`DatabaseDefaultLanguageException` ("Default language record with id … not found").

The repair command finds and fixes those leftovers:

```bash
# Report what would change (default)
vendor/bin/typo3 autotranslate:references:repair

# Write the changes
vendor/bin/typo3 autotranslate:references:repair --apply

# Limit the run to specific reference tables
vendor/bin/typo3 autotranslate:references:repair --table=sys_file_reference
```

It covers every table configured in `additionalReferenceTables` and reports each
record with the reason before changing anything:

| Case | Action |
|------|--------|
| Several translations of the same source record | Keep the oldest, delete the rest |
| Translation without a pointer to its default language record, whose source is already translated | Delete the unlinked copy |
| Translation without a pointer to its default language record, whose source is untranslated | Reconnect it to that source |
| Translation pointing at a record that no longer exists | Delete it |

Records are soft-deleted, so they stay recoverable in the recycler. Run
`referenceindex:update` afterwards to refresh `sys_refindex`.
