<?php
/**
 * Zend Framework (http://framework.zend.com/)
 *
 * @link      http://github.com/zendframework/ZendSkeletonApplication for the canonical source repository
 * @copyright Copyright (c) 2005-2013 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 */

namespace Cli;

use Zend\ModuleManager\Feature\AutoloaderProviderInterface;
use Zend\ModuleManager\Feature\ConfigProviderInterface;
use Zend\ModuleManager\Feature\ConsoleUsageProviderInterface;
use Zend\Console\Adapter\AdapterInterface as Console;

class Module implements
    AutoloaderProviderInterface,
    ConfigProviderInterface,
    ConsoleUsageProviderInterface
{
    
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function getAutoloaderConfig()
    {
        return array(
            'Zend\Loader\StandardAutoloader' => array(
                'namespaces' => array(
                    __NAMESPACE__ => __DIR__ . '/src/' . __NAMESPACE__,
                ),
            ),
        );
    }
    
    public function getConsoleBanner(Console $console){
        return "Cli Module";
    }
    
    public function getConsoleUsage(Console $console)
    {
        return array(
            // Describe available commands
            'taskManagerDaemon'     => 'Запуск обработчика заданий, запускается по incron или cron',
            'loadYaMarketTyreModelsImages' => 'Загрузка фотографий моделей шин с ЯМаркет',

            // Describe expected parameters
            array( 'параметр',            'что делает' ),
            array( '--verbose|-v',     '(optional) turn on verbose mode'        ),
        );
    }
}
