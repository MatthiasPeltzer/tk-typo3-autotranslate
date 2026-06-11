# Fields to be translated

You must specify which fields you want the service to translate.

The service reads possible fields from the TCA and suggests them. The fields must be entered in the text box separated by commas. Entered fields are filtered out of the suggestion.

![text-fields](../../Images/TextFields.png)

You must define what types of files should be translated by the service.

![file-reference](../../Images/FileReference.png)

You can also define text fields of the files to be translated.

![SysFileReferenceTextFields.png](../../Images/SysFileReferenceTextFields.png)

## Manual translation adjustments

When an editor changes a translated field manually, TYPO3 may mark it as `custom` in `l10n_state`. Autotranslate **does not overwrite** such fields on subsequent saves or batch runs unless the **corresponding source field** changed.

Editing FAL metadata (e.g. image alt or title) on a source record can trigger translation of the localized file reference when that reference column is configured — even if the parent content element body text did not change.
