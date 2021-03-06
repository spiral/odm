<?php
/**
 * Spiral Framework, SpiralScout LLC.
 *
 * @author    Anton Titov (Wolfy-J)
 */
define('SPIRAL_INITIAL_TIME', microtime(true));

error_reporting(E_ALL | E_STRICT);
ini_set('display_errors', true);
mb_internal_encoding('UTF-8');

//Composer
require dirname(__DIR__) . '/vendor/autoload.php';

//File component fixtures
define('FIXTURE_DIRECTORY', __DIR__ . '/Files/fixtures/');
define('ENABLE_PROFILING', getenv('PROFILING') ?? false);