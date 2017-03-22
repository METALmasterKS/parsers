<?php

namespace Cli\Controller;

use Zend\Mvc\Controller\AbstractActionController;

use Zend\Db\Adapter\Driver\ResultInterface;
use Zend\Db\ResultSet\ResultSet;

class ParseController extends AbstractActionController {
    
    public function PastelAction() {
        set_time_limit(3*60*60);
        
        $host = 'http://pastel.su';
        
        $resultData = [];
        
        $files = $this->GetImportFiles('pastel');
        $file = current($files);

        echo $file->getFilename()."\n";
        $PHPExcel = \PHPExcel_IOFactory::load($file->getRealPath());
        $PHPExcel->setActiveSheetIndex(0);
        $sheetData = $PHPExcel->getActiveSheet()->toArray(null,false,false,false);
        $lastStrNum = count($sheetData);
        $j = 1;
        foreach ($sheetData as $i => $row) {
            if (!isset($row[1]) && $j < 5) {
                $url = trim($row[0]);
                $data = $this->parseUrl($url);
                if (count($data['variantsUrls'])) {
                    foreach ($data['variantsUrls'] as $varUrl) {
                        $PHPExcel->setCellValueByColumnAndRow(0, $lastStrNum, $host.$varUrl);
                        $lastStrNum++;
                    }
                } else {
                    $resultData[] = $data;
                }
                
                $PHPExcel->setActiveSheetIndex(0)->setCellValueByColumnAndRow(1, $i+1, 'parsed');
                $j++;
                sleep(rand(4,10)); // задержка чтоб сильно не наглеть
            }
        }

        $PHPExcel->setActiveSheetIndex(1);
        $sheetData = $PHPExcel->getActiveSheet()->toArray(null,false,false,false);
        $lastStrNum = count($sheetData);
        foreach ($resultData as $data) {
            $PHPExcel->setActiveSheetIndex(1)
                ->setCellValueByColumnAndRow(0, $lastStrNum, $data['code'])
                ->setCellValueByColumnAndRow(1, $lastStrNum, $data['name'])
                ->setCellValueByColumnAndRow(2, $lastStrNum, $data['descr'])
                ->setCellValueByColumnAndRow(3, $lastStrNum, $data['features'])
                ->setCellValueByColumnAndRow(4, $lastStrNum, $data['images']);
            
            $lastStrNum++;
        }
        
        // Write the file
        $objWriter = \PHPExcel_IOFactory::createWriter($PHPExcel, 'Excel2007');
        $objWriter->save($file->getRealPath());
        
    }
    
    private function parseUrl($url) {
        $convEnc = $this->ConvertEncoding()->setIn('ISO-8859-1');
        
        $userAgFiles = $this->GetImportFiles('useragents');
        $userAgents = ['Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:34.0) Gecko/20100101 Firefox/34.0'];
        foreach ($userAgFiles as $file) 
            $userAgents = array_merge($userAgents, array_map('trim', file($file->getRealPath())));
        shuffle($userAgents);
        
        $config = array(
            'adapter'   => 'Zend\Http\Client\Adapter\Curl',
            'keepalive' => TRUE,
            'curloptions' => array(
                CURLOPT_FOLLOWLOCATION => true, 
                CURLOPT_USERAGENT => $userAgents[0],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 6,
            ),
        );
        
        $data = array('code' => '', 'name' => '', 'descr' => '', 'features' => '', 'images' => '', 'variantsUrls' => []);
        try {
            $client = new \Zend\Http\Client($url, $config);
            $response = $client->send();
            $dom = new \Zend\Dom\Query($response->getBody(), 'UTF-8');
            
            //code
            $res = $dom->execute('span.b-product__sku');
            if ($res->count()) 
                $data['code'] = $res->current()->textContent;
                //$data['code'] = preg_replace("/[^0-9]/", '', $res->current()->textContent);
            
            //h1 
            $res = $dom->execute("h1");
            if ($res->count()) 
                $data['name'] = $convEnc->convert($res->current()->textContent);
            
            //descr
            $res = $dom->execute("div.b-content__body.b-user-content");
            if ($res->count()) 
                $data['descr'] = $res->current()->nodeValue;
            
            // характеристики
            $features = array();
            $results = $dom->execute('table.b-product-info > tr > td');
            if ($results->count() > 0) {
                for ($i = 0; $i < $results->count(); $i+=2) {
                    $name = trim($results->current()->nodeValue);
                    $results->next();
                    $value = trim($results->current()->nodeValue);
                    $results->next();
                    if ($name != null)
                        $features[] = sprintf ("%s:%s", $name, $value);
                }
            }
            $data['features'] = implode(',', $features);
            
            //картинки
            $images = [];
            $results = $dom->execute('a.b-product__image, a.b-product__additional-image');
            foreach ($results as $result) {
                $images[] = $result->getAttribute('href');
            }
            $data['images'] = implode(',', $images);
            
        } catch (Exception $exc) {
            return $data;
        }
            
        return $data;
    }
    
    
}
