<?php

declare(strict_types=1);

namespace ThieleUndKlose\Autotranslate\EventListener;

use ThieleUndKlose\Autotranslate\Utility\GlossaryBackendUtility;
use TYPO3\CMS\Backend\Controller\Event\RenderAdditionalContentToRecordListEvent;

final readonly class GlossaryRecordListListener
{
    public function __invoke(RenderAdditionalContentToRecordListEvent $event): void
    {
        $request = $event->getRequest();
        $pageId = GlossaryBackendUtility::resolvePageId($request);
        if ($pageId <= 0 || !GlossaryBackendUtility::canUserSyncFolder($pageId)) {
            return;
        }

        $event->addContentAbove(GlossaryBackendUtility::renderSyncPanel($pageId, $request));
    }
}
