<?php

namespace Cli\Controller\Plugin;

use Zend\Mvc\Controller\Plugin\AbstractPlugin;

class GetImportFiles extends AbstractPlugin
{
    
    public function __invoke($dir, $filenames = null) {
        $config = $this->getController()->getServiceLocator()->get('config');
        if (!isset($config["settings"]['import'][$dir]))
            die('Could not find \'[settings][import]['.$dir.']\' configuration key');
        
        $importCatalogConfig = $config["settings"]['import'][$dir];
        $filepaths = glob($importCatalogConfig['path'].$importCatalogConfig['mask']);
        $files = array();
        foreach ($filepaths as $filepath){
            $file = new \SplFileInfo($filepath);
            if (is_array($filenames)) {
                if (in_array($file->getFilename(), $filenames))
                    $files[] = $file;
            } else
                $files[] = $file;
        }
        return $files;
    }

}
