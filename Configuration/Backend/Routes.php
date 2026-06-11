<?php

declare(strict_types=1);

return [
    'autotranslate_glossary_sync' => [
        'path' => '/autotranslate/glossary/sync',
        'methods' => ['POST'],
        'target' => \ThieleUndKlose\Autotranslate\Controller\GlossarySyncController::class . '::syncFolderAction',
    ],
];
