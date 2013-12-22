<?php
namespace core;

class ClassLoader
{
    public $path = null;

    public function __construct($path)
    {
        $this->path = $path;
    }

    public function autoload($className)
    {
        $filePath = $this->path . str_replace('\\', DIRECTORY_SEPARATOR, $className) . '.php';
        if (file_exists($filePath) === false || is_readable($filePath) === false) {
            return false;
        }
        require $filePath;
        return true;
    }

    public function register()
    {
        if (function_exists('__autoload')) {
            spl_autoload_register('__autoload');
        }
        return spl_autoload_register(array($this, 'autoload'));
    }
}
