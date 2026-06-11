===================
AutoTranslate
===================

:Extension key:
   autotranslate

:Package name:
   thieleundklose/autotranslate

:Version:
   |release|

:Language:
   en

:Rendered:
   |today|

----

This documentation describes the TYPO3 extension "AutoTranslate".

----

**Table of contents:**

.. toctree::
   :maxdepth: 2
   :titlesonly:

   Introduction/Index
   Installation/Index
   Configuration/Index
   Usage/Index

===================
Introduction
===================

What is AutoTranslate?
---------------------

AutoTranslate is a TYPO3 extension that provides automatic translations of pages and content elements via the DeepL API. The extension supports TYPO3 v13.4 LTS and v14, with PHP 8.2 - 8.5.

Features
--------

* Automatic translation of pages and content elements
* Integration with the DeepL API
* Batch translation for large amounts of content
* Support for recurring translations
* Translation of file references and their metadata
* User-friendly backend module
* Translation modes: "Create & Update", "Update only", "Create only"
* Dedicated scheduler task with visual progress bar
* Duplicate batch item prevention
* Error reporting for failed translation items
* Translation caching to reduce API calls and costs
* Changed-fields-only mode for saves, batch, and scheduler (extension setting)
* Protection of manually customized translations via ``l10n_state``
* Direct translation of reference records (e.g. FAL alt/title edits)
* Glossary support (via deepltranslate_glossary)
* Grid Elements support
* Site-specific API keys
* German backend translations
* Batch module access control and POST-only mutations

===================
Installation
===================

Installation via Composer
-------------------------

The recommended installation method is via Composer:

.. code-block:: bash

   composer require thieleundklose/autotranslate

===================
Configuration
===================

Setting up the DeepL API key
-----------------------------

1. Register for a DeepL API key at https://www.deepl.com/pro-api
2. Open the TYPO3 Site Configuration
3. Enter the DeepL API key in the ``deeplAuthKey`` field

Language configuration
------------------

1. Open the TYPO3 Site Configuration
2. Configure the languages in the Site Configuration
3. For each language you can set the following:
   * ``deeplSourceLang``: The source language for DeepL (e.g. "DE")
   * ``deeplTargetLang``: The target language for DeepL (e.g. "EN")

Configuring translatable tables
---------------------------------

1. Open the TYPO3 Site Configuration
2. For each table you can configure the following:
   * ``autotranslate_[tablename]_enabled``: Enable automatic translation for the table
   * ``autotranslate_[tablename]_languages``: Comma-separated list of target languages
   * ``autotranslate_[tablename]_textfields``: Comma-separated list of text fields to translate
   * ``autotranslate_[tablename]_fileReferences``: Comma-separated list of file references to translate

Additional tables
-------------------

You can add more tables for translation:

1. Open the Extension Configuration
2. Add the additional tables under the ``additionalTables`` key (comma-separated)
3. Provide the required database fields in your site package (see Extension Configuration docs)

Extension setting ``translateChangedFieldsOnly``
---------------------------------------------------

When enabled (default):

* On **save**, only changed translatable fields are sent to DeepL.
* On **batch / scheduler**, only fields with changed source content are translated (tracked via ``autotranslate_source_hash``).
* **New records** and **first localizations** still translate all configured fields.
* Fields marked ``custom`` in ``l10n_state`` are preserved unless the source field changed.

===================
Usage
===================

Automatic translation
----------------------

The extension translates content on save when:

1. The table is enabled in the Site Configuration
2. The fields for translation are configured
3. The target languages are correctly set up

On **updates**, only changed configured fields are translated when ``translateChangedFieldsOnly`` is enabled. Saving a reference record directly (e.g. FAL alt/title) can update localized references without changing parent body text.

Batch translation
---------------

1. Open the backend module "AutoTranslate"
2. Select the elements to be translated
3. Configure the translation settings:
   * Target language
   * Translation frequency (once or recurring)
   * Translation time
4. Start the translation

CLI Usage
---------

Translate queued items via command line:

.. code-block:: bash

   # Translate 50 items (default)
   vendor/bin/typo3 autotranslate:batch:run

   # Translate 10 items
   vendor/bin/typo3 autotranslate:batch:run 10

Translation status
----------------

In the backend module you can:

* View the status of all translations
* Reset failed translations
* View translation logs
* Clear the translation cache

Batch execute, delete, and reset require POST requests and page/language access for each item.

Notes on translation quality
-------------------------------

* Translation quality depends on the DeepL API
* Review automatically translated content for correct technical terms
* You can manually adjust translations if needed

===================
Support
===================

For questions or issues please contact:

* E-Mail: typo3@thieleundklose.de
* Website: https://www.thieleundklose.de
