<?php

declare(strict_types=1);

namespace ThieleUndKlose\Autotranslate\EventListener;

use ThieleUndKlose\Autotranslate\Utility\GlossaryBackendUtility;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Template\Components\ModifyButtonBarEvent;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;

final readonly class GlossarySyncButtonBarListener
{
    public function __construct(
        private ComponentFactory $componentFactory,
        private IconFactory $iconFactory,
    ) {}

    public function __invoke(ModifyButtonBarEvent $event): void
    {
        $request = $event->getRequest();
        $pageId = GlossaryBackendUtility::resolvePageId($request);
        if ($pageId <= 0 || !GlossaryBackendUtility::canUserSyncFolder($pageId)) {
            return;
        }

        $buttons = $event->getButtons();
        $buttons[ButtonBar::BUTTON_POSITION_LEFT] ??= [];
        $buttons[ButtonBar::BUTTON_POSITION_LEFT][5] ??= [];
        $buttons[ButtonBar::BUTTON_POSITION_LEFT][5][] = $this->componentFactory
            ->createGenericButton()
            ->setTag('button')
            ->setLabel(GlossaryBackendUtility::translate('glossary.sync.button'))
            ->setTitle(GlossaryBackendUtility::translate('glossary.sync.button'))
            ->setIcon($this->iconFactory->getIcon('actions-refresh', IconSize::SMALL))
            ->setShowLabelText(true)
            ->setAttributes([
                'type' => 'submit',
                'form' => GlossaryBackendUtility::getSyncFormId($pageId),
            ]);
        $event->setButtons($buttons);
    }
}
