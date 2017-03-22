<?php

/**
 * Консольный контроллер, вешаем на крон, наприме каждую минуту
 * ~ php index.php taskManagerDaemon
 *
 */

namespace Cli\Controller;

use Zend\Mvc\Controller\AbstractActionController;

use Zend\Db\Adapter\Driver\ResultInterface;
use Zend\Db\ResultSet\ResultSet;

class IndexController extends AbstractActionController {
    
    public function indexAction() {
        
    }
    
    public function taskManagerDaemonAction() {
        $this->getServiceLocator()->get('Log\Console')->info('Запуск Console Task Manager'); 
        $task = $this->CliTaskManager()->getFirstTask();
        if ($task === false) {
            $this->getServiceLocator()->get('Log\Console')->info('Console Task Manager занят'); 
            return;
        } elseif ($task == null) {
            $this->getServiceLocator()->get('Log\Console')->info('Нет поставленных задач'); 
            return;
        }
        $taskArr = explode('/', $task);
        $controller = "Cli\Controller\\".$taskArr[0];
        $action = $taskArr[1];
        //Проверка существования контроллера и экшена
        $loader = $this->getServiceLocator()->get('ControllerLoader');
        if ($loader->has($controller)) {
            $obj    = $loader->get($controller);
            $method = $obj::getMethodFromAction($action);
            if (method_exists($obj, $method)) {
                $this->getServiceLocator()->get('Log\Console')->info('Запуск задачи '.$controller." - ".$method); 
                echo $controller." - ".$method."\n";
                //запуск
                return $this->forward()->dispatch($controller, ['action' => $action]);
            }
        }
    }

}
