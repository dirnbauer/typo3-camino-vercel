<?php

declare(strict_types=1);

namespace Webconsulting\Typo3VercelBlobStorage\EventListener;

use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Template\Components\ModifyButtonBarEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Resource\StorageRepository;

#[AsEventListener]
final readonly class AddLargeUploadButton
{
    public function __construct(
        private ComponentFactory $componentFactory,
        private IconFactory $iconFactory,
        private UriBuilder $uriBuilder,
        private StorageRepository $storageRepository,
    ) {}

    public function __invoke(ModifyButtonBarEvent $event): void
    {
        $request = $event->getRequest();
        if ($request->getAttribute('route')?->getOption('_identifier') !== 'media_management') {
            return;
        }

        $parsedBody = $request->getParsedBody();
        $combinedIdentifier = $request->getQueryParams()['id'] ?? (is_array($parsedBody) ? ($parsedBody['id'] ?? null) : null);
        if (!is_string($combinedIdentifier) || $combinedIdentifier === '') {
            return;
        }
        $storage = $this->storageRepository->findByCombinedIdentifier($combinedIdentifier);
        if ($storage === null
            || $storage->getDriverType() !== 'vercel_blob'
            || !$storage->isOnline()
            || !$storage->isWritable()
            || !$storage->checkUserActionPermission('add', 'File')
        ) {
            return;
        }

        $button = $this->componentFactory->createLinkButton()
            ->setHref((string)$this->uriBuilder->buildUriFromRoute(
                'media_vercel_blob_large_upload',
                ['id' => $combinedIdentifier],
            ))
            ->setTitle('Large upload')
            ->setShowLabelText(true)
            ->setIcon($this->iconFactory->getIcon('actions-upload', IconSize::SMALL));

        $buttons = $event->getButtons();
        $buttons[ButtonBar::BUTTON_POSITION_LEFT][4][] = clone $button;
        $event->setButtons($buttons);
    }
}
