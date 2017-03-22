<?php

return array(
    'settings' => array(
        'import' => [
            'tyres' => [
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
                
                'pastel.su' => array(
                    'options' => array(
                        'route'    => 'pastel.su',
                        'defaults' => array(
                            'controller' => 'Cli\Controller\Parse',
                            'action'     => 'Pastel'
                        )
                    )
                ),
                
            )
        )
    ),

    'controllers' => array(
        'invokables' => array(
            'Cli\Controller\Index'     => 'Cli\Controller\IndexController',
            'Cli\Controller\Parse'     => 'Cli\Controller\ParseController',
        ),
    ),
    'controller_plugins' => array(
        'invokables' => array(
            'CliTaskManager'   => 'Cli\Controller\Plugin\CliTaskManager',
            'GetImportFiles'    => 'Cli\Controller\Plugin\GetImportFiles',
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
