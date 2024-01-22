<?php

// autoload_real.php @generated by Composer

class ComposerAutoloaderInit7fa0ab7637c6bd32c88f66de506b24ca
{
    private static $loader;

    public static function loadClassLoader($class)
    {
        if ('Composer\Autoload\ClassLoader' === $class) {
            require __DIR__ . '/ClassLoader.php';
        }
    }

    /**
     * @return \Composer\Autoload\ClassLoader
     */
    public static function getLoader()
    {
        if (null !== self::$loader) {
            return self::$loader;
        }

        spl_autoload_register(array('ComposerAutoloaderInit7fa0ab7637c6bd32c88f66de506b24ca', 'loadClassLoader'), true, true);
        self::$loader = $loader = new \Composer\Autoload\ClassLoader(\dirname(__DIR__));
        spl_autoload_unregister(array('ComposerAutoloaderInit7fa0ab7637c6bd32c88f66de506b24ca', 'loadClassLoader'));

        require __DIR__ . '/autoload_static.php';
        call_user_func(\Composer\Autoload\ComposerStaticInit7fa0ab7637c6bd32c88f66de506b24ca::getInitializer($loader));

        $loader->register(true);

        return $loader;
    }
}
