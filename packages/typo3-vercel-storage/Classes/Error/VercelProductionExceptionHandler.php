<?php

declare(strict_types=1);

namespace Webconsulting\Typo3VercelStorage\Error;

use TYPO3\CMS\Core\Error\ProductionExceptionHandler;

final class VercelProductionExceptionHandler extends ProductionExceptionHandler
{
    public function echoExceptionWeb(\Throwable $exception)
    {
        $this->logToPhpErrorLog($exception);
        parent::echoExceptionWeb($exception);
    }

    public function echoExceptionCLI(\Throwable $exception)
    {
        $this->logToPhpErrorLog($exception);
        parent::echoExceptionCLI($exception);
    }

    private function logToPhpErrorLog(\Throwable $exception): void
    {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $message = sprintf(
            'TYPO3 production exception: %s #%s at %s:%d on %s: %s',
            $exception::class,
            (string)$exception->getCode(),
            $exception->getFile(),
            $exception->getLine(),
            $requestUri !== '' ? $requestUri : 'CLI',
            $exception->getMessage(),
        );

        error_log($message);
    }
}
