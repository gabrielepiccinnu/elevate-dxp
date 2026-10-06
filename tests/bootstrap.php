<?php

declare(strict_types=1);

/*
 * Unit tests run against the package's own vendor/ (composer install in this repository).
 * Alternatively, point ELEVATE_DXP_AUTOLOAD at the autoloader of an OpenDXP application that
 * requires this bundle (useful inside the OpenDXP docker image).
 */
$autoload = getenv('ELEVATE_DXP_AUTOLOAD') ?: __DIR__.'/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Autoloader not found: run `composer install` or set ELEVATE_DXP_AUTOLOAD.\n");
    exit(1);
}
$loader = require $autoload;
$loader->addPsr4('ElevateDxp\\', __DIR__.'/../src/');
$loader->addPsr4('ElevateDxp\\Tests\\', __DIR__.'/');
