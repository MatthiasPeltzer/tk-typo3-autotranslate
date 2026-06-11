<?php

declare(strict_types=1);

namespace ThieleUndKlose\Autotranslate\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ThieleUndKlose\Autotranslate\Service\GlossarySyncService;
use ThieleUndKlose\Autotranslate\Utility\GlossaryBackendUtility;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class GlossarySyncController
{
    public function __construct(
        private readonly GlossarySyncService $glossarySyncService,
        private readonly UriBuilder $uriBuilder,
        private readonly FlashMessageService $flashMessageService,
    ) {}

    public function syncFolderAction(ServerRequestInterface $request): ResponseInterface
    {
        $queryParams = $request->getQueryParams();
        $parsedBody = $request->getParsedBody();
        $parsedBody = is_array($parsedBody) ? $parsedBody : [];

        $pageId = (int)($parsedBody['id'] ?? $queryParams['id'] ?? 0);
        $returnUrl = GeneralUtility::sanitizeLocalUrl(
            (string)($parsedBody['returnUrl'] ?? $queryParams['returnUrl'] ?? ''),
            $request
        );

        if ($pageId <= 0 || !GlossaryBackendUtility::canUserSyncFolder($pageId)) {
            $this->addFlashMessage(
                'You do not have permission to synchronize glossaries on this page.',
                'Glossary sync',
                ContextualFeedbackSeverity::ERROR
            );

            return new RedirectResponse($this->resolveReturnUrl($returnUrl));
        }

        $results = $this->glossarySyncService->syncFolder($pageId);
        $successCount = 0;
        foreach ($results as $result) {
            $severity = $result->success
                ? ContextualFeedbackSeverity::OK
                : ContextualFeedbackSeverity::ERROR;
            if ($result->success) {
                $successCount++;
            }
            $this->addFlashMessage($result->message, 'Glossary sync', $severity);
        }

        if ($successCount > 0 && $successCount < count($results)) {
            $this->addFlashMessage(
                sprintf('%d of %d glossaries synchronized successfully.', $successCount, count($results)),
                'Glossary sync',
                ContextualFeedbackSeverity::WARNING
            );
        }

        return new RedirectResponse($this->resolveReturnUrl($returnUrl));
    }

    private function addFlashMessage(string $message, string $title, ContextualFeedbackSeverity $severity): void
    {
        $this->flashMessageService
            ->getMessageQueueByIdentifier()
            ->addMessage(new FlashMessage($message, $title, $severity, true));
    }

    private function resolveReturnUrl(string $returnUrl): string
    {
        if ($returnUrl !== '') {
            return $returnUrl;
        }

        return (string)$this->uriBuilder->buildUriFromRoute('web_layout');
    }
}
