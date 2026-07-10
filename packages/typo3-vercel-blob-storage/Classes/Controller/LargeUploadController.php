<?php

declare(strict_types=1);

namespace Webconsulting\Typo3VercelBlobStorage\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\JsonResponse;
use Webconsulting\Typo3VercelBlobStorage\DirectUpload\DirectUploadService;
use Webconsulting\Typo3VercelBlobStorage\Exception\DirectUploadException;

#[AsController]
final class LargeUploadController implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly UriBuilder $uriBuilder,
        private readonly DirectUploadService $directUploadService,
    ) {}

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle('Large upload');
        $combinedIdentifier = $request->getQueryParams()['id'] ?? null;
        $error = null;
        $folder = null;
        try {
            $folder = $this->directUploadService->targetFolder(is_string($combinedIdentifier) ? $combinedIdentifier : null);
        } catch (DirectUploadException $exception) {
            $error = $exception->getMessage();
        }

        $view->assignMultiple([
            'configured' => $folder !== null,
            'error' => $error,
            'folder' => $folder,
            'maximumSizeInBytes' => $this->directUploadService->maximumSizeInBytes(),
            'prepareUrl' => (string)$this->uriBuilder->buildUriFromRoute('media_vercel_blob_large_upload.prepare'),
            'authorizeUrl' => (string)$this->uriBuilder->buildUriFromRoute('media_vercel_blob_large_upload.authorize'),
            'finalizeUrl' => (string)$this->uriBuilder->buildUriFromRoute('media_vercel_blob_large_upload.finalize'),
            'fileListUrl' => $folder === null
                ? (string)$this->uriBuilder->buildUriFromRoute('media_management')
                : (string)$this->uriBuilder->buildUriFromRoute('media_management', ['id' => $folder->getCombinedIdentifier()]),
        ]);

        return $view->renderResponse('LargeUpload/Index');
    }

    public function prepareAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->jsonAction($request, $this->directUploadService->prepare(...));
    }

    public function authorizeAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->jsonAction($request, $this->directUploadService->authorize(...));
    }

    public function finalizeAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->jsonAction($request, $this->directUploadService->finalize(...));
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $action
     */
    private function jsonAction(ServerRequestInterface $request, callable $action): ResponseInterface
    {
        try {
            $body = json_decode((string)$request->getBody(), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($body)) {
                throw new DirectUploadException('A JSON request body is required.', 400);
            }
            return $this->jsonResponse($action($body));
        } catch (DirectUploadException $exception) {
            if ($exception->getHttpStatus() >= 500) {
                $this->logger?->error('Vercel Blob direct upload failed.', ['exception' => $exception]);
            }
            return $this->jsonResponse(['error' => $exception->getMessage()], $exception->getHttpStatus());
        } catch (\JsonException) {
            return $this->jsonResponse(['error' => 'The request body is not valid JSON.'], 400);
        } catch (\Throwable $exception) {
            $this->logger?->error('Unexpected Vercel Blob direct-upload failure.', ['exception' => $exception]);
            return $this->jsonResponse(['error' => 'The upload could not be completed. Retry in a moment.'], 500);
        }
    }

    /** @param array<string, mixed> $data */
    private function jsonResponse(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status, [
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
