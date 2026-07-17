#!/usr/bin/env bash
# Package-specific settings for Build/Scripts/runTests.sh (autotranslate).

NETWORK_PREFIX="autotranslate"
COMPOSER_ROOT_VERSION="${COMPOSER_ROOT_VERSION:-3.0.9-dev}"
UNIT_PHPUNIT_CONFIG="Build/phpunit/UnitTests.xml"
FUNCTIONAL_PHPUNIT_CONFIG="Build/phpunit/FunctionalTests.xml"
PHPSTAN_CONFIG="Build/phpstan.neon"
PHP_CS_FIXER_CONFIG=".php-cs-fixer.dist.php"
SQLITE_TMPFS_DIR=".Build/public/typo3temp/var/tests/functional-sqlite-dbs"
WEB_PUBLIC_DIR=".Build/public"
ENABLE_ACCEPTANCE=1
ENABLE_JS_UNIT=0
ENABLE_FRONTEND_LINT=0
FRONTEND_BUILD_DIR=""
