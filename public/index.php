<?php

/**
 * Front controller. The only PHP file under the web root — everything else
 * lives outside it and is reached through the router.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$bootstrap = require __DIR__ . '/../config/bootstrap.php';

$bootstrap()->run();
