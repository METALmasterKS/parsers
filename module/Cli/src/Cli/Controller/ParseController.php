<?php

namespace Cli\Controller;

use Zend\Mvc\Controller\AbstractActionController;

use Zend\Db\Adapter\Driver\ResultInterface;
use Zend\Db\ResultSet\ResultSet;

class ParseController extends AbstractActionController {
    
    private $authCookieFile = 'data/yandex/marketImagesGrabber/cookie.txt';

    public function PastelAction() {
        set_time_limit(3*60*60);
        $brandTable = $this->getServiceLocator()->get('TyresModelBrandTable');
        $brands = $brandTable->getBrands(['tyresCountLoad' => true, 'order' => 'name', 'tyresCountGreaterThan' => 0, ]);

        $modelTable = $this->getServiceLocator()->get('TyresModelModelTable');
        $tyreModels = $modelTable->getModels(['images' => '']);
        
        $brandsWithModels = [];
        foreach ($brands as $brand){
            foreach ($tyreModels as $model)
                if ($model->brandId == $brand->id)
                    $brand->addModel($model);
            $brandsWithModels[$brand->id] = $brand;
        }
        unset($brands, $tyreModels);
        
        $this->auth();
        
        foreach ($brandsWithModels as $brand) {
            if (count($brand->getModels()) > 0)
            foreach ($brand->getModels() as $model) {
                $fullname = "{$brand->name} {$model->name}";
                $imgs = $this->getImages($fullname);
                if (count($imgs)){
                    $tmpimgs = $this->loadTmpImages($imgs);

                    $imageCreator = $this->ModelImageCreator($model);
                    foreach ($tmpimgs as $imgfile) {
                        if ($imageCreator->updateImageFile($imgfile)){
                            $model->addImage($imageCreator->getImageFileName());
                        }
                    }

                    $modelTable->saveModel($model);
                }
                
                sleep(rand(2,4)); // задержка чтоб сильно не наглеть
            } 
        }
        
    }
    
    private function auth($force = FALSE){
        if (!file_exists($this->authCookieFile) || $force == true){
            $config = array(
                'adapter'   => 'Zend\Http\Client\Adapter\Curl',
                'curloptions' => array(
                    CURLOPT_FOLLOWLOCATION => true, 
                    CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:34.0) Gecko/20100101 Firefox/34.0',
                    CURLOPT_COOKIEJAR => $this->authCookieFile,
                    CURLOPT_POST => 1,
                    CURLOPT_POSTFIELDS => http_build_query([
                            'login' => 'partspostavka',
                            'passwd' =>	'postavka2016',
                            'state' => 'submit',
                        ])
                ),
            );
            $client = new \Zend\Http\Client('https://passport.yandex.ru/passport?mode=auth&retpath=https://mail.yandex.ru', $config);
            $client->send();
            
        }
    }
    
    private function getImages($fullname) {
        $imgs = array();
        
        $config = array(
            'adapter'   => 'Zend\Http\Client\Adapter\Curl',
            'keepalive' => TRUE,
            'curloptions' => array(
                CURLOPT_FOLLOWLOCATION => true, 
                CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:34.0) Gecko/20100101 Firefox/34.0',
                CURLOPT_COOKIEFILE => $this->authCookieFile,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 6,
            ),
        );
        $client = new \Zend\Http\Client('https://yandex.ru/suggest-market/suggest-market', $config);
        $client->setParameterGet(array(
           'part' => $fullname,
        ));
        try {
            $response = $client->send();
            $yaResult = json_decode($response->getContent());
        } catch (Exception $exc) {
            return [];
        }

        if (empty($yaResult[1]) || empty($yaResult[2]))
            return [];
        
        $key = array_search($yaResult[0], $yaResult[1]);
        $url = $yaResult[2][$key];
        echo $fullname.' - '.'https://market.yandex.ru'.$url."\n";
        if ($url == null)
            return [];
        
        try {
            $client = new \Zend\Http\Client('https://market.yandex.ru'.$url, $config);
            $response = $client->send();

            $dom = new \Zend\Dom\Query($response->getBody());
            $results = $dom->execute("div.product-card-gallery__thumbs > ul > li > a");

            if ($results->count() > 0) {
                foreach ($results as $result) {
                    $imgs[] = 'http://'.ltrim($result->getAttribute('href'), '/');
                }
            } else {
                return [];
            }
        } catch (Exception $exc) {
            return [];
        }
            
        return $imgs;
    }
    
    
    private function loadTmpImages($imgs){
        $tmpimgs = [];
        foreach ($imgs as $img) {
            $tmpimgs[] = $tmp = 'data/yandex/marketImagesGrabber/'.uniqid().'.jpg';
            copy($img, $tmp);
            $orig = new \upload($tmp);
            if ($orig->uploaded && $orig->image_src_x != $orig->image_src_y) {
                $bigSide = $orig->image_src_x > $orig->image_src_y ? $orig->image_src_x : $orig->image_src_y;
                
                $orig->file_overwrite = true;

                $halfCropX = ($orig->image_src_x - $bigSide) / 2;
                $halfCropY = ($orig->image_src_y - $bigSide) / 2;
                $orig->image_crop = sprintf("%s %s %s %s", floor($halfCropY), floor($halfCropX), ceil($halfCropY), ceil($halfCropX));

                $orig->process(dirname($tmp));
            }
        }
        
        return $tmpimgs;
    }
    

}
