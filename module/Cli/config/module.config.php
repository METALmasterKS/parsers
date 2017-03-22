<?php

return array(
    'settings' => array(
        'import' => [
            'useragents' => [
                'path' => 'data/import/',
                'mask' => "useragents.txt",
            ],
            'pastel' => [
                'path' => 'data/pastel/',
                'mask' => "pastel.xl*",
            ],
        ],
    ),
    'console' => array(
        'router' => array(
            'routes' => array(
                'index' => [
                    'options' => array(
                        'route'    => 'index',
                        'defaults' => array(
                            'controller' => 'Cli\Controller\Index',
                            'action'     => 'index'
                        )
                    )
                ],
                'taskManagerDaemon' => [
                    'options' => array(
                        'route'    => 'taskManagerDaemon',
                        'defaults' => array(
                            'controller' => 'Cli\Controller\Index',
                            'action'     => 'taskManagerDaemon'
                        )
                    )
                ],
                
                'pastelsu' => array(
                    'options' => array(
                        'route'    => 'pastelsu',
                        'defaults' => array(
                            'controller' => 'Cli\Controller\Pastel',
                            'action'     => 'index'
                        )
                    )
                ),
                
            )
        )
    ),

    'controllers' => array(
        'invokables' => array(
            'Cli\Controller\Index'     => 'Cli\Controller\IndexController',
            'Cli\Controller\Pastel'     => 'Cli\Controller\PastelController',
        ),
    ),
    'controller_plugins' => array(
        'invokables' => array(
            'CliTaskManager'   => 'Cli\Controller\Plugin\CliTaskManager',
            'GetImportFiles'    => 'Cli\Controller\Plugin\GetImportFiles',
            'ConvertEncoding'    => 'Cli\Controller\Plugin\ConvertEncoding',
        )
    ),
    'service_manager' => array(
        'abstract_factories' => array(
            'Zend\Log\LoggerAbstractServiceFactory',
        ),
    ),
    'log' => array(
        'Log\Console' => array(
            'writers' => array(
                array(
                    'name' => 'stream',
                    'priority' => 1000,
                    'options' => array(
                        'stream' => 'data/logs/console.log',
                    ),
                ),
            ),
        ),
    ),

    
);
