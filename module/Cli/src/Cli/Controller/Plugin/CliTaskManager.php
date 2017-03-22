<?php

namespace Cli\Controller\Plugin;

use Zend\Mvc\Controller\Plugin\AbstractPlugin;

class CliTaskManager extends AbstractPlugin
{
    const START = 'start';
    const SUCCESS = 'success';
    const PROCESS = 'process';
    
    private $tasksFilePath = 'data/import/tasklist';
    
    public function __invoke() {
        $this->readTasks();
        return $this;
    }
    
    public function readTasks() {
        $taskfile = fopen($this->tasksFilePath, 'r');
        if ($taskfile === false)
            return array();

        while (($data = fgetcsv($taskfile, 64, ":")) !== false) {
            $this->tasks[trim($data[0])] = trim($data[1]);
        }
        fclose($taskfile);

        return $this->tasks;
    }

    public function echoTasks() {
        foreach ($this->tasks as $task => $status) {
            echo "$task -> $status\n";
        }
        return $this;
    }
    
    public function getFirstTask() {
        foreach ($this->tasks as $task => $status) {
            if ($status == 'process')
                return false;
            if ($status == 'start')
                return $task;
        }
    }

    public function saveTaskStatus($taskName, $status, $writeNow = true) {
        $this->tasks[$taskName] = $status;
        if ($writeNow)
            $this->writeTasks();
        return $this;
    }

    public function writeTasks() {
        $taskfile = fopen($this->tasksFilePath, 'w');
        if ($taskfile === false)
            return false;

        foreach ($this->tasks as $task => $status) {
            fputcsv($taskfile, array($task, $status), ':');
        }

        fclose($taskfile);
        /*$rows = array();
        foreach ($this->tasks as $task => $status)
            $rows[] = implode(':', array($task, $status));
        file_put_contents($this->tasksFilePath, implode("\n", $rows));*/
    }

}
