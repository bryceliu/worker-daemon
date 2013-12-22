<?php
declare(ticks = 1);
define('ROOT', dirname(__FILE__) . '/');

require ROOT . 'core/ClassLoader.php';
$loader = new core\ClassLoader(ROOT);
$loader->register();

$daemon = new worker\HelloWorld();
$conf = include(ROOT . 'conf/helloworld.php');
foreach ($conf as $key => $value) {
    $daemon->$key = $value;
}
$daemon->run();
