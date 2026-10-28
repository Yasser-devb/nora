<?php

declare(strict_types=1);

use Nora\Core\Application;
use Nora\Reactive\ReactiveEngine;
use Nora\Security\SessionManager;
use NoraUiDemo\Reactive\DashboardPage;

require dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'vendor'
    . DIRECTORY_SEPARATOR . 'autoload.php';
require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src'
    . DIRECTORY_SEPARATOR . 'Reactive' . DIRECTORY_SEPARATOR . 'DashboardPage.php';

$application = new Application();
$engine = new ReactiveEngine(new SessionManager(false));
$engine->register('dashboard', new DashboardPage());
$engine->mount($application, 'dashboard', '/', 'Nora UI Integration Demo');
$application->run();
