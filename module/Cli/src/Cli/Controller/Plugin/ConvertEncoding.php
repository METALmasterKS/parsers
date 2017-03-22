<?php

namespace Cli\Controller\Plugin;

use Zend\Mvc\Controller\Plugin\AbstractPlugin;

class ConvertEncoding extends AbstractPlugin
{
    private $iconv;
    private $in;
    private $out;

    public function __invoke($val = null) {
        $this->iconv = new \Zend\Stdlib\StringWrapper\Iconv();
        $this->in = 'CP1251';
        $this->out = 'UTF-8';
        $this->setEncoding();
        
        if (isset($val) && is_string($val))
            return $this->convert($val);
        else 
            return $this;
    }
    
    public function convert(&$val, &$key = null) {
        if (is_array($val))
            array_walk_recursive($val, array($this, 'convert'));
        else {
            $key = $this->iconv->convert($key);
            $val = $this->iconv->convert($val);
            return $val;
        }
    }
        
    public function setIn($val) {
        $this->in = $val;
        $this->setEncoding();
        return $this;
    }
    
    public function setOut($val) {
        $this->out = $val;
        $this->setEncoding();
        return $this;
    }
    
    public function setEncoding(){
        $this->iconv->setEncoding($this->in, $this->out);
    }
    
}
