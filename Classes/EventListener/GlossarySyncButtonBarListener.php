<?php

declare(strict_types=1);

namespace ThieleUndKlose\Autotranslate\EventListener;

use ThieleUndKlose\Autotranslate\Utility\GlossaryBackendUtility;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\Buttons\GenericButton;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Template\Components\ModifyButtonBarEvent;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final readonly class GlossarySyncButtonBarListener
{
    public function __construct(
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
        $buttons[ButtonBar::BUTTON_POSITION_LEFT][5][] = $this->createGenericButton()
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

    /**
     * Create a generic button in a TYPO3 v13.4- and v14-compatible way.
     *
     * TYPO3 v14 introduced ComponentFactory::createGenericButton(); on v13.4 the
     * GenericButton is instantiated directly. Resolving this at call time (rather
     * than via constructor injection) keeps the listener autowireable on v13.4,
     * where ComponentFactory does not exist.
     */
    private function createGenericButton(): GenericButton
    {
        if (class_exists(ComponentFactory::class)) {
            return GeneralUtility::makeInstance(ComponentFactory::class)->createGenericButton();
        }

        return GeneralUtility::makeInstance(GenericButton::class);
    }
}
