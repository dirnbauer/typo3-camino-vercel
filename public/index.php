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
    if ((getenv('VERCEL') === '1' || getenv('VERCEL_URL') !== false) && PHP_OS_FAMILY !== 'Windows') {
        foreach (
            [
                '/tmp/typo3',
                '/tmp/typo3/var',
                '/tmp/typo3/var/cache',
                '/tmp/typo3/var/lock',
                '/tmp/typo3/var/log',
                '/tmp/typo3/tmp',
                '/tmp/typo3/gm',
                '/tmp/typo3/php-sessions',
            ] as $runtimePath
        ) {
            if (!is_dir($runtimePath)) {
                @mkdir($runtimePath, 0777, true);
            }
            @chmod($runtimePath, 0777);
        }
    }

    $classLoader = require dirname(__DIR__).'/vendor/autoload.php';
    require_once dirname(__DIR__).'/scripts/typo3-env.php';
    \TYPO3\CMS\Core\Core\SystemEnvironmentBuilder::run();

    // The `?__typo3_install` switch would otherwise turn every public URL into an
    // Install Tool entry point. Allow TYPO3's authenticated backend-module
    // context, while standalone public access remains an explicit opt-in.
    $isInstallToolDirectAccess = class_exists(\TYPO3\CMS\Install\Http\Application::class)
        && typo3_vercel_install_tool_direct_access($_GET, $_COOKIE, $_POST);

    $container = \TYPO3\CMS\Core\Core\Bootstrap::init($classLoader, $isInstallToolDirectAccess);

    if ($container->has(\TYPO3\CMS\Core\Http\Application::class)) {
        $container->get(\TYPO3\CMS\Core\Http\Application::class)->run();
        return;
    }

    $container->get(\TYPO3\CMS\Install\Http\Application::class)->run();
});
