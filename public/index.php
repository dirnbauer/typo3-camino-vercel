<?php

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

call_user_func(static function () {
    $classLoader = require dirname(__DIR__).'/vendor/autoload.php';
    \TYPO3\CMS\Core\Core\SystemEnvironmentBuilder::run();

    // The `?__typo3_install` switch would otherwise turn every public URL into an
    // Install Tool entry point (Vercel routes all paths here, so it cannot be
    // blocked by path-based rules). Only honour it when the operator has opted in
    // by setting an Install Tool password hash or TYPO3_INSTALL_TOOL_ENABLED=1.
    $isInstallToolDirectAccess = false;
    if (isset($_GET['__typo3_install']) && class_exists(\TYPO3\CMS\Install\Http\Application::class)) {
        $installEnabled = in_array(strtolower((string)getenv('TYPO3_INSTALL_TOOL_ENABLED')), ['1', 'true', 'yes', 'on'], true);
        $installHash = (string)getenv('TYPO3_INSTALL_TOOL_PASSWORD_HASH');
        $isInstallToolDirectAccess = $installEnabled || $installHash !== '';
    }

    $container = \TYPO3\CMS\Core\Core\Bootstrap::init($classLoader, $isInstallToolDirectAccess);

    if ($container->has(\TYPO3\CMS\Core\Http\Application::class)) {
        $container->get(\TYPO3\CMS\Core\Http\Application::class)->run();
        return;
    }

    $container->get(\TYPO3\CMS\Install\Http\Application::class)->run();
});
