<?php

declare(strict_types=1);

namespace ThieleUndKlose\Autotranslate\EventListener;

use TYPO3\CMS\Backend\Controller\Event\AfterPageTreeItemsPreparedEvent;

/**
 * Ensures glossary sysfolders use the batch translation record icon in the page tree.
 */
final readonly class GlossaryPageTreeIconListener
{
    private const MODULE = 'autotranslate_glossary';

    private const ICON = 'tcarecords-tx_autotranslate_batch_item-default';

    public function __invoke(AfterPageTreeItemsPreparedEvent $event): void
    {
        $items = $event->getItems();
        $changed = false;

        foreach ($items as &$item) {
            $page = $item['_page'] ?? null;
            if (!is_array($page) || !$this->isGlossaryFolder($page)) {
                continue;
            }
            if (($item['icon'] ?? '') !== self::ICON) {
                $item['icon'] = self::ICON;
                $changed = true;
            }
        }
        unset($item);

        if ($changed) {
            $event->setItems($items);
        }
    }

    private function isGlossaryFolder(array $page): bool
    {
        $module = $page['module'] ?? '';
        if (is_array($module)) {
            $module = (string)reset($module);
        }

        return (string)$module === self::MODULE;
    }
}
