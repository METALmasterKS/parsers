<?php

/**
 * Zend Framework (http://framework.zend.com/)
 *
 * @link      http://github.com/zendframework/ZendSkeletonApplication for the canonical source repository
 * @copyright Copyright (c) 2005-2013 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 */

namespace Cli\Controller;

use Zend\Mvc\Controller\AbstractActionController;

use Zend\Db\Adapter\Driver\ResultInterface;
use Zend\Db\ResultSet\ResultSet;

class ImportController extends AbstractActionController {
    private $start;
    
    private $tyresBrands = null;
    private $brandIdPatternMap = null;
    private $tyresModels = null;
    private $brandIdModelIdPatterns = null;
    private $cities = null;

    private $tmp_table = 'tyres_import_tmp';
    
    private $uniqTyreMask = "%s-%s/%sR%s-%s%s-%s-%s-%s";

    private function preDispatch() {
        $this->start = microtime(true);
    }
    
    private function postDispatch($e) {
        echo $text = sprintf("Скрипт ".  get_class($this)." - ".$this->params('action')." выполнялся %.4F сек.\n", (microtime(true) - $this->start));
        $this->getServiceLocator()->get('Log\Console')->info(trim($text)); 
    }
    
    public function onDispatch( \Zend\Mvc\MvcEvent $e ) {
        $this->preDispatch();
        parent::onDispatch( $e );
        $this->postDispatch( $e );
    }

    public function TyresAction(){
        set_time_limit(60);
        ini_set('memory_limit', '768M');
        
        $this->CliTaskManager()->saveTaskStatus('Import/Tyres', \Cli\Controller\Plugin\CliTaskManager::PROCESS);

        //очищаем
        $dbAdapter = $this->getServiceLocator()->get('Zend\Db\Adapter\Adapter');
        $dbAdapter->query('DELETE FROM `tyres_import_tmp`', $dbAdapter::QUERY_MODE_EXECUTE);
        
        // у каждого поставщика свой скрипт импорта
        $providerTable = $this->getServiceLocator()->get('TyresModelProviderTable');
        $providers = $providerTable->getProviders();
        foreach ($providers as $provider){
            if ($provider->files != null){
                $files = explode(';', $provider->files);
                switch ($provider->name) {
                    case 'НордВестШина':
                        $this->NordWestShina($files, $provider);
                        break;
                    case 'АйсАвто':
                        $this->IceAuto($files, $provider);
                        break;
                    case 'Ласерта':
                        $this->Laserta($files, $provider);
                        break;
                    case 'Питер Шина':
                        $this->PiterShina($files, $provider);
                        break;
                    case 'Север Авто':
                        $this->SeverAuto($files, $provider);
                        break;
                    case '4точки':
                        $this->Tochki($files, $provider);
                        break;
                }
            }
        }
        
        
        $this->updateTmpTyreModelIds();

        // @TODO может быть очищать таблицы перед инсертом.
        $this->processTmpTyres();
        
        $this->CliTaskManager()
        //отметим импорт завершенным
            ->saveTaskStatus('Import/Tyres', \Cli\Controller\Plugin\CliTaskManager::SUCCESS);
        
    }
    
    private function NordWestShina($filenames, $provider){
        $files = $this->GetImportFiles('tyres', $filenames);
        
        $tyresData = array();
        foreach ($files as $file) {
            echo $file->getFilename()."\n";
            $objPHPExcel = \PHPExcel_IOFactory::load($file->getRealPath());
            $objPHPExcel->setActiveSheetIndex(0);
            $sheetData = $objPHPExcel->getActiveSheet()->toArray(null,false,false,false);
            foreach ($sheetData as $i => $row) {
                if (trim($row[0]) == null || trim($row[1]) == null || trim($row[2]) == null || trim($row[3]) == null) 
                    continue;
                
                $data = [];
                $data['name'] = $name = trim($row[3]);
                $data['model'] = '';
                $data['XL'] = 0;
                $data['RFT'] = 0;
                $data['width']        = trim($row[0]);
                $data['height']       = trim($row[1]);
                $data['diameter']       = trim($row[2]);
                $data['quantity']     = trim($row[4]);
                $data['price']        = trim($row[5]);
                $data['spikes'] = 0;
                $data['providerId'] = $provider->id;
                $data['cityId'] = 1; //Cанкт Петербург
                $data['brandId'] = '';
                
                //поэтапно вырезаем из наименования части, чтоб получить модель 
                //находим и вырезаем бренд
                foreach ($this->getBrandsPatterns() as $brandId => $ptrn){
                    if (preg_match($ptrn, $name, $matches)) {
                        $data['brandId'] = $brandId;
                        $name = trim(preg_replace($ptrn, '', $name));
                        break;
                    }
                }
                
                //вырезаем размер
                $sizePtrn = "/".preg_quote($data['width'], '/')."\/".preg_quote($data['height'], '/')."[ \/]?Z?R".preg_quote($data['diameter'], '/')."/iu";
                $name = trim(preg_replace($sizePtrn, '', $name));
                
                //шипы
                if (preg_match("/шип/", $name))
                    $data['spikes'] = 1;
                if (preg_match("/нешип/", $name))
                    $data['spikes'] = 0;
                //вырезаем шипность из наименования
                $spikePtrn = "/(шип|нешип)\.?/iu";
                $name = trim(preg_replace($spikePtrn, '', $name));
                    
                //находим индексы скорости и нагрузки и вырезаем
                $loadSpeedPtrn = "/(([0-9]{2,3})(\/[0-9]{2,3})?)([PQRSTUHVWYZ]{1,2})/u";
                $data['load'] = $data['speed'] = '';
                if (preg_match($loadSpeedPtrn, $name, $matches)){
                    $data['load'] = $matches[1];
                    $data['speed'] = $matches[4];
                    $name = trim(preg_replace($loadSpeedPtrn, '', $name));
                }
                
                //находи ранфлет
                if (preg_match($this->getRunFlatPattern(), $name)) {
                    $data['RFT'] = 1;
                    $name = trim(preg_replace($this->getRunFlatPattern('array'), '', $name));
                }
                //находим екстра лоад 
                $xlPtrn = "/(XL)/iu";
                if (preg_match($xlPtrn, $name)) {
                    $data['XL'] = 1;
                    $name = trim(preg_replace($xlPtrn, '', $name));
                }
                //убираем двойные пробелы
                $name = trim(preg_replace("/ {2,}/u", ' ', $name));
                //убираем шина
                $name = trim(preg_replace("/^шин[а-я]+ /iu", ' ', $name));
                //оставщееся это модель
                $data['model'] = $name;

                $tyresData[] = $data;
            }
        }
        
        $this->saveToTmpImport($tyresData);
    }
    
    private function IceAuto($filenames, $provider){
        $files = $this->GetImportFiles('tyres', $filenames);
        
        $tyresData = array();
        foreach ($files as $file) {
            $season = preg_replace(
                ["/^.*ЛЕТ.+$/iu", "/^.*зим.+$/iu"],
                ['summer', 'winter'],
                $file->getFilename()
            );
            $objPHPExcel = \PHPExcel_IOFactory::load($file->getRealPath());
            $objPHPExcel->setActiveSheetIndex(0);
            $sheetData = $objPHPExcel->getActiveSheet()->toArray(null,false,false,false);
            echo $file->getFilename()."\n";
            foreach ($sheetData as $i => $row) {
                $pattern = "/^R[0-9]{2}C? [0-9]{3}\/[0-9]{2} (.*){1} (([0-9]{2,3})(\/[0-9]{2,3})?)([PQRSTUHVWYZ]{1}) (.*){1}$/i";
                if (!isset($row[2]) || !preg_match($pattern, trim($row[2]), $matches)) 
                    continue;
                
                $data = [];
                $data['name'] = $name = trim($row[2]);
                $data['XL'] = 0;
                $data['RFT'] = 0;
                $data['width']        = trim($row[9]);
                $data['height']       = trim($row[10]);
                $data['diameter']       = str_replace('R','', trim($row[8]));
                $data['quantity']     = intval(trim($row[4]));
                $data['price']        = trim($row[3]);
                $data['spikes']       = trim($row[11]) == 'Есть' ? 1 : 0;
                $data['season']       = $season;
                $data['providerId']   = $provider->id;
                $data['cityId'] = 1; //Cанкт Петербург
                $data['load']       = $matches[2];
                $data['speed']       = $matches[5];
                
                
                $brand = trim($row[7]);
                $model = $matches[1];
                $data['brandId'] = '';
                foreach ($this->getBrandsPatterns() as $brandId => $ptrn){
                    if (preg_match($ptrn, $brand)) {
                        $data['brandId'] = $brandId;
                        $model = trim(preg_replace($ptrn, '', $model));
                        break;
                    }
                }
                // убираем то что в скобках 
                $model = trim(preg_replace("/\(.+\)/u", ' ', $model));
                //убираем двойные пробелы
                $model = trim(preg_replace("/ {2,}/u", ' ', $model));
                // опции - то что находится за индексами нагр и ск-ти
                $options = $matches[6];
                
                $data['sale'] = 0;
                if (preg_match("/уцен[^\s]?/", $options))
                    $data['sale'] = 1;
                
                //находим ранфлет
                if (preg_match($this->getRunFlatPattern(), $options)) {
                    $data['RFT'] = 1;
                    $model = trim(preg_replace($this->getRunFlatPattern('array'), '', $model));
                }
                //находим екстра лоад 
                $xlPtrn = "/(XL)/iu";
                if (preg_match($xlPtrn, $options)) {
                    $data['XL'] = 1;
                }
                
                $data['model'] = $model;    
                
                $tyresData[] = $data;
            }
        }
        
        $this->saveToTmpImport($tyresData);
    }
    
    private function Laserta($filenames, $provider){
        $files = $this->GetImportFiles('tyres', $filenames);
        
        $Cities = $this->getCities();
        $CityIdColId = [
            1 => 9, //Питер
            2 => 8, //москва
            3 => 6, //Екатеринбург
            4 => 7, //Краснодар
        ];
        
        $brands = [];
        foreach ($this->getTyresBrands() as $brand){
            $brands[$brand->id] = $brand->name;
        }
        
        $brandNameIdMap = [];
        foreach ($this->getTyresBrands() as $brand){
            $brandNameIdMap[$brand->name] = $brand->id;
            $aliases = explode(';', $brand->aliases);
            foreach ($aliases as $alias)
                $brandNameIdMap[$alias] = $brand->id;
        }

        // узнаем размер нагрузку и скорость
        $pattern = "/^(.*) ([A-Z]{1-2})?([0-9]{3})\/([0-9]{2})(R[0-9]{2}C?) (([0-9]{2,3})(\/[0-9]{2,3})?)([PQRSTUHVWYZ]{1}) (.*){1}$/";
        
        $seasonPtrns = ['/(^.*летн.*$)/i', '/(^.*зим.*$)/i', '/(^.*всесезон.*$)/i'];
        $seasonEnum = ['summer', 'winter', 'allseason'];
        
        $tyresData = array();
        foreach ($files as $file) {
            echo $file->getFilename()."\n";
            $objPHPExcel = \PHPExcel_IOFactory::load($file->getRealPath());
            $objPHPExcel->setActiveSheetIndex(0);
            $sheetData = $objPHPExcel->getActiveSheet()->toArray(null,false,false,false);
            
            $lastBrandId = '';
            $lastSeason = '';
            $lastModel = '';
            foreach ($sheetData as $i => $row) {
                if ($i<3 || implode('', $row) == null) //пропускаем первые 3 строки
                    continue;

                if (implode('', array_slice($row, 4, 7)) == null ){
                    $smthg = trim($row[2]); // узнаем что это и записываем last
                    
                    if (in_array(preg_replace($seasonPtrns, $seasonEnum, $smthg), $seasonEnum)) {
                        $lastSeason = preg_replace($seasonPtrns, $seasonEnum, $smthg);
                    } elseif (isset($brandNameIdMap[$smthg])) {
                        $lastBrandId = $brandNameIdMap[$smthg];
                    } else {
                        $lastModel = $smthg;
                    }
                } elseif (preg_match($pattern, trim($row[2]), $matches)) {
                    $data = [];
                    $name = $matches[10];
                    $data['name'] = trim($row[2]);
                    $data['price']        = trim($row[5]);
                    $data['quantity']     = ltrim(trim($row[9]), '>'); // по дефолту питер
                    $data['providerId']   = $provider->id;
                    $data['brandId']      = $lastBrandId;
                    $data['model']      = $lastModel;
                    $data['season']      = $lastSeason;
                    $data['XL'] = 0;
                    $data['RFT'] = 0;
                    $data['width']        = $matches[3];
                    $data['height']       = $matches[4];
                    $data['diameter']       = str_replace('R','', $matches[5]);
                    $data['load']       = $matches[6];
                    $data['speed']       = $matches[9];

                    $season = preg_replace($seasonPtrns, $seasonEnum, trim($row[3]));
                    if (in_array($season, $seasonEnum) && $lastSeason != $season) //зезон по строке в приоритете
                        $data['season'] = $season;


                    $data['sale'] = 0;
                    $salePattern = "/распр\.?/i"; //проверяем на распродажу и стираем в наименовании
                    if (preg_match($salePattern, $name)) {
                        $data['sale'] = 1;
                        $name = trim(preg_replace($salePattern, '', $name));
                    }

                    $data['spikes'] = 0;
                    $spikePattern = "/шип\.?/i"; //проверяем на шипованную и стираем в наименовании
                    if (preg_match($spikePattern, $name)) {
                        $data['spikes'] = 1;
                        $name = trim(preg_replace($spikePattern, '', $name));
                    }

                    if ($lastModel != null){
                        $name = trim(preg_replace("/".preg_quote($lastModel, '/')."/iu", '', $name));
                    }

                    //находи ранфлет
                    if (preg_match($this->getRunFlatPattern(), $name)) {
                        $data['RFT'] = 1;
                        $name = trim(preg_replace($this->getRunFlatPattern('array'), '', $name));
                    }
                    //находим екстра лоад 
                    $xlPtrn = "/(XL)/iu";
                    if (preg_match($xlPtrn, $name)) {
                        $data['XL'] = 1;
                        $name = trim(preg_replace($xlPtrn, '', $name));
                    }

                    //убираем двойные пробелы
                    $name = preg_replace("/ {2,}/u", ' ', $name);

                    foreach ($CityIdColId as $cityId => $colId){
                        $data['cityId'] = $cityId;
                        $data['quantity'] = ltrim(trim($row[$colId]), '>');

                        $tyresData[] = $data;
                    }
                    
                }
            }
        }
        
        $this->saveToTmpImport($tyresData);
    }
    
    private function PiterShina($filenames, $provider){
        $files = $this->GetImportFiles('tyres', $filenames);
        
        // узнаем размер нагрузку и скорость
        $pattern = "/^([A-Z]{1-2})?([0-9]{3})\/([0-9]{2})(R[0-9]{2}C?) (.*){1} (([0-9]{2,3})(\/[0-9]{2,3})?)([PQRSTUHVWYZ]{1})$/";
        
        $tyresData = array();
        foreach ($files as $file) {
            $objPHPExcel = \PHPExcel_IOFactory::load($file->getRealPath());
            $objPHPExcel->setActiveSheetIndex(0);
            $sheetData = $objPHPExcel->getActiveSheet()->toArray(null,false,false,false);
            echo $file->getFilename()."\n";
            foreach ($sheetData as $i => $row) {
                $name = trim($row[0]);
                if (preg_match($pattern, $name, $matches)) {
                    $data = [];
                    $name = $matches[5];
                    $data['name'] = $row[0];
                    $data['price'] = trim($row[2]);
                    $data['quantity'] = trim($row[1]);
                    $data['providerId'] = $provider->id;
                    $data['cityId'] = 1; //Cанкт Петербург
                    $data['XL'] = 0;
                    $data['RFT'] = 0;
                    $data['width'] = $matches[2];
                    $data['height'] = $matches[3];
                    $data['diameter'] = str_replace('R','', $matches[4]);
                    $data['load'] = $matches[6];
                    $data['speed'] = $matches[9];
                    
                    $data['brandId'] = '';
                    foreach ($this->getBrandsPatterns() as $brandId => $ptrn){
                        if (preg_match($ptrn, $name)) {
                            $data['brandId'] = $brandId;
                            $name = trim(preg_replace($ptrn, '', $name));
                            break;
                        }
                    }
                    
                    //находим ранфлет
                    if (preg_match($this->getRunFlatPattern(), $name)) {
                        $data['RFT'] = 1;
                        $name = trim(preg_replace($this->getRunFlatPattern('array'), '', $name));
                    }
                    //находим екстра лоад 
                    $xlPtrn = "/(XL)/iu";
                    if (preg_match($xlPtrn, $name)) {
                        $data['XL'] = 1;
                        $name = trim(preg_replace($xlPtrn, '', $name));
                    }
                    
                    //убираем двойные пробелы
                    $name = preg_replace("/ {2,}/u", ' ', $name);
                    
                    $data['model'] = $name;

                    $tyresData[] = $data;
                }
            }
        }
        
        $this->saveToTmpImport($tyresData);
    }
    
    private function SeverAuto($filenames, $provider){
        $files = $this->GetImportFiles('tyres', $filenames);
        
        $tyresData = array();
        foreach ($files as $file) {
            $objPHPExcel = \PHPExcel_IOFactory::load($file->getRealPath());
            $objPHPExcel->setActiveSheetIndex(0);
            $sheetData = $objPHPExcel->getActiveSheet()->toArray(null,false,false,false);
            echo $file->getFilename()."\n";
            foreach ($sheetData as $i => $row) {
                $data = [];
                if ($i == 0 || implode('', $row) == null)
                    continue;
                $data['name'] = $name = $row[1];
                $data['quantity'] = trim($row[2]);
                $data['price'] = trim($row[5]);
                $data['providerId'] = $provider->id;
                $data['cityId'] = 1; //Cанкт Петербург
                $data['width'] = trim($row[12]);
                $data['height'] = trim($row[13]);
                $data['diameter'] = str_replace('R','', trim($row[11]));
                $data['XL'] = 0;
                $data['RFT'] = 0;
                $speed = trim($row[15]);
                $data['speed'] = '';
                if (preg_match("/([PQRSTUHVWYZ]{1})/u", $speed, $matches))
                    $data['speed'] = $matches[1];

                $data['model'] = $model = trim($row[10]);
                $brandName = trim($row[9]) == 'Срш' ? $model : trim($row[9]);
                $data['brandId'] = '';
                foreach ($this->getBrandsPatterns() as $brandId => $ptrn){
                    if (preg_match($ptrn, $brandName)) {
                        $data['brandId'] = $brandId;
                        $model = trim(preg_replace($ptrn, '', $model));
                        break;
                    }
                }
                
                //находим ранфлет
                if (preg_match($this->getRunFlatPattern(), $model)) {
                    $data['RFT'] = 1;
                    $model = trim(preg_replace($this->getRunFlatPattern('array'), '', $model));
                }
                //находим екстра лоад 
                $xlPtrn = "/(XL)/iu";
                if (preg_match($xlPtrn, $model)) {
                    $data['XL'] = 1;
                    $model = trim(preg_replace($xlPtrn, '', $model));
                }
                //убираем двойные пробелы
                $model = preg_replace("/ {2,}/u", ' ', $model);
                
                $data['model'] = $model;
                
                $speedPtrn = ($data['speed'] == null ? "[PQRSTUHVWYZ]{1,2}" : preg_quote($data['speed'], '/'));
                $loadSpeedPtrn = "/(".$speedPtrn.") +([шШ]\.?)? ?(([0-9]{2,3})(\/[0-9]{2,3})?)/u";
                $data['load'] = '';
                if (preg_match($loadSpeedPtrn, $name, $matches)) {
                    $data['load'] = $matches[3];
                    if ($data['speed'] == null) 
                        $data['speed'] = $matches[1];
                }

                $data['sale'] = 0;
                if (preg_match("/да/iu", trim($row[6])))
                    $data['sale'] = 1;
                
                $data['spikes'] = 0;
                if (preg_match("/^Ш\.$/i", trim($row[14])))
                    $data['spikes'] = 1;
                
                $data['season'] = '';
                $season = trim($row[8]);
                $seasonPtrns = ['/(^.*летн.*$)/iu', '/(^.*зим.*$)/iu', '/(^.*всесезон.*$)/iu'];
                $seasonEnum = ['summer', 'winter', 'allseason'];
                if (in_array(preg_replace($seasonPtrns, $seasonEnum, $season), $seasonEnum)) 
                    $data['season'] = preg_replace($seasonPtrns, $seasonEnum, $season);

                $tyresData[] = $data;
            }
        }
        
        $this->saveToTmpImport($tyresData);
    }
    
    private function Tochki($filenames, $provider){
        $files = $this->GetImportFiles('tyres', $filenames);

        $Cities = $this->getCities();
        $CityIdSheetId = [
            1 => 0, //Питер
            2 => 1, //москва
        ]; 
        
        foreach ($files as $file) {
            $tyresData = array();
            echo $file->getFilename()."\n";
            $objPHPExcel = \PHPExcel_IOFactory::load($file->getRealPath());
            $sheetNames = $objPHPExcel->getSheetNames();
            foreach ($CityIdSheetId as $CityId => $sheetId) {
                echo $sheetNames[$sheetId].' - '.$Cities[$CityId]->name."\n";
                $objPHPExcel->setActiveSheetIndex($sheetId);
                $sheetData = $objPHPExcel->getActiveSheet()->toArray(null,false,false,false);
                foreach ($sheetData as $i => $row) {
                    $data = [];
                    if ($i == 0 || implode('', $row) == null)
                        continue;
                    $data['name'] = sprintf("%s %s %s/%s %s %s", trim($row[1]), trim($row[2]), trim($row[3]), trim($row[4]), trim($row[5]), trim($row[6]) );
                    $data['model'] = trim($row[2]);
                    $data['quantity'] = trim($row[19]);
                    $data['price'] = trim($row[21]);
                    $data['providerId'] = $provider->id;
                    $data['cityId'] = $CityId;
                    $data['width'] = trim($row[3]);
                    $data['height'] = trim($row[4]);
                    $data['diameter'] = preg_replace("/Z?R([0-9]{1,2})(,0)?(C)?/iu","$1$3", trim($row[5]));
                    $data['XL'] = (preg_match("/да/iu", trim($row[16])) ? 1 : 0);
                    $data['RFT'] = (preg_match("/да/iu", trim($row[13])) ? 1 : 0);
                    $data['spikes'] = (preg_match("/да/iu", trim($row[10])) ? 1 : 0);
                    $data['sale'] = 0;
                    
                    $brand = trim($row[1]);
                    $data['brandId'] = '';
                    foreach ($this->getBrandsPatterns() as $brandId => $ptrn){
                        if (preg_match($ptrn, $brand)) {
                            $data['brandId'] = $brandId;
                            break;
                        }
                    }

                    $speed = trim($row[6]);
                    $data['speed'] = '';
                    if (preg_match("/([PQRSTUHVWYZ]{1})/u", $speed, $matches)) {
                        $data['speed'] = $matches[1];
                    }
                    $speedPtrn = ($data['speed'] == null ? "[PQRSTUHVWYZ]{1,2}" : preg_quote($data['speed'], '/'));
                    $loadSpeedPtrn = "/(".$speedPtrn.") +([шШ]\.?)? ?(([0-9]{2,3})(\/[0-9]{2,3})?)/u";
                    $data['load'] = '';
                    if (preg_match($loadSpeedPtrn, $speed, $matches)) {
                        $data['load'] = $matches[3];
                        if ($data['speed'] == null) 
                            $data['speed'] = $matches[1];
                    }

                    $data['season'] = '';
                    $season = trim($row[7]);
                    $seasonPtrns = ['/(^.*летн.*$)/iu', '/(^.*зим.*$)/iu', '/(^.*всесезон.*$)/iu'];
                    $seasonEnum = ['summer', 'winter', 'allseason'];
                    if (in_array(preg_replace($seasonPtrns, $seasonEnum, $season), $seasonEnum)) 
                        $data['season'] = preg_replace($seasonPtrns, $seasonEnum, $season);

                    $tyresData[] = $data;
                }
            }
            unset($objPHPExcel, $sheetData, $sheetNames);
            $this->saveToTmpImport($tyresData);
        }
        
    }

        private function getTyresBrands(){
        if (!isset($this->tyresBrands)){
            $this->tyresBrands = $this->getServiceLocator()->get('TyresModelBrandTable')->getBrands();
        }
        return $this->tyresBrands;
    }
    
    private function getBrandsPatterns() {
        if (!isset($this->brandIdPatternMap)){
            $this->brandIdPatternMap = [];
            foreach ($this->getTyresBrands() as $brand) {
                $vars = $brand->aliases != null ? array_merge(explode(';', $brand->aliases), [$brand->name]) : [$brand->name];
                usort($vars, function ($a, $b) {
                    if (mb_strlen($a) == mb_strlen($b))
                        return 0;
                    return (mb_strlen($a) > mb_strlen($b)) ? -1 : 1;
                });
                array_walk($vars, function (&$x){
                    $x = preg_quote($x, '/');
                });
                $this->brandIdPatternMap[$brand->id] = "/(".implode('|', $vars).")/iu";
            }
        }
        return $this->brandIdPatternMap;
    }
    
    private $runFlatStrs = ['Run Flat','RunFlat','run on flat','ROF','RFT','RSC','SSR','ZP','r-f','rf',];
    private function getRunFlatPattern($type = null) {
        $runflat = $this->runFlatStrs;
        
        if ($type == 'array') {
            array_walk($runflat, function (&$x){
                $x = "/".preg_quote($x, '/')."/iu";
            });
            return $runflat;
        } else {
            array_walk($runflat, function (&$x){
                $x = preg_quote($x, '/');
            });
            return "/(".implode('|', $runflat).")/iu";
        }
    }

    private function saveToTmpImport($tyresData){
        if (!count($tyresData))
            return false;
        $dbAdapter = $this->getServiceLocator()->get('Zend\Db\Adapter\Adapter');
        $sql = new \Zend\Db\Sql\Sql($dbAdapter);
        // вставка во временную таблицу импорта для крона
        $insert = $sql->insert($this->tmp_table);
        $insert->columns(array_keys(current($tyresData)));
        $statement = $sql->prepareStatementForSqlObject($insert);
        $dbAdapter->getDriver()->getConnection()->beginTransaction();
        foreach ($tyresData as $tyreData) {
            $results = $statement->execute($tyreData);
        }
        $dbAdapter->getDriver()->getConnection()->commit();
    }
    
    private function getCities($where = null, $map = false){
        $dbAdapter = $this->getServiceLocator()->get('Zend\Db\Adapter\Adapter');
        $sql = new \Zend\Db\Sql\Sql($dbAdapter);
        $select = $sql->select('geo_city');
        if ($where != null)
            $select->where($where);
        $rows = $dbAdapter->query($sql->getSqlStringForSqlObject($select), $dbAdapter::QUERY_MODE_EXECUTE);
        $rows->buffer();
        
        $arr = [];
        foreach ($rows as $row)
            if ($map) 
                $arr[$row->id] = $row->name;
            else 
                $arr[$row->id] = $row;
        return $arr;
        
    }
    
    private function updateTmpTyreModelIds() {
        $tmpTyreIdModelId = array(); 
        //поиск модели по регулярке из вариантов написания модели внутри бренда
        foreach ($this->getBrandIdModelIdPatterns() as $brandId => $modelIdPatterns) {
            $brandTmpTyres = $this->getTmpTyres(['brandId' => $brandId]);
            foreach ($brandTmpTyres as $tmpTyre){
                foreach ($modelIdPatterns as $modelId => $ptrn){
                    if (preg_match($ptrn, $tmpTyre->model)){
                        $tmpTyreIdModelId[$tmpTyre->id] = $modelId;
                        break;
                    }
                }
            }
        }
        $dbAdapter = $this->getServiceLocator()->get('Zend\Db\Adapter\Adapter');
        $sql = new \Zend\Db\Sql\Sql($dbAdapter);
        //апдейтнем tmp tyres modelId
        $expr = 'CASE ';
        foreach ($tmpTyreIdModelId as $tmpTyreId => $modelId)
            $expr .= "WHEN id = ".$tmpTyreId." THEN ".$modelId." ";
        $expr .= " ELSE modelId END";
        $update = $sql->update($this->tmp_table)->where(['id' => array_keys($tmpTyreIdModelId)])->set([
            'modelId' => new \Zend\Db\Sql\Expression($expr),
        ]);
        $dbAdapter->query($sql->getSqlStringForSqlObject($update), $dbAdapter::QUERY_MODE_EXECUTE);
        
    }
    
    private function getTyresModels(){
        if (!isset($this->tyresModels)){
            $this->tyresModels = $this->getServiceLocator()->get('TyresModelModelTable')->getModels();
        }
        return $this->tyresModels;
    }
    
    private function getBrandIdModelIdPatterns() {
        if (!isset($this->brandIdModelIdPatterns)) {
            $this->brandIdModelIdPatterns = [];
            foreach ($this->getTyresModels() as $model) {
                $vars = $model->aliases != null ? array_merge(explode(';', $model->aliases), [$model->name]) : [$model->name];
                usort($vars, function ($a, $b) {
                    if (mb_strlen($a) == mb_strlen($b))
                        return 0;
                    return (mb_strlen($a) > mb_strlen($b)) ? -1 : 1;
                });
                array_walk($vars, function (&$x){
                    $x = preg_quote($x, '/');
                });
                $this->brandIdModelIdPatterns[$model->brandId][$model->id] = "/^(".implode('|', $vars).")$/iu";
            }
        }
        return $this->brandIdModelIdPatterns;
    }
    
    private function getTmpTyres($where = null){
        $dbAdapter = $this->getServiceLocator()->get('Zend\Db\Adapter\Adapter');
        $sql = new \Zend\Db\Sql\Sql($dbAdapter);
        $select = $sql->select($this->tmp_table);
        if ($where != null)
            $select->where($where);
        $rows = $dbAdapter->query($sql->getSqlStringForSqlObject($select), $dbAdapter::QUERY_MODE_EXECUTE);
        $rows->buffer();
        return $rows;
    }
    
    private function processTmpTyres(){
        $tyresTableName = 'tyres';
        $pricesTableName = 'tyres_prices';
        
        $dbAdapter = $this->getServiceLocator()->get('Zend\Db\Adapter\Adapter');
        $platform = $dbAdapter->getPlatform();
        $sql = new \Zend\Db\Sql\Sql($dbAdapter);
        $metadata = new \Zend\Db\Metadata\Metadata($dbAdapter);
        
        $tmpTyres = $this->getTmpTyres();
        
        //записываем в tyres размерности и опции шин
        $dbAdapter->getDriver()->getConnection()->beginTransaction();
        $tyresFields = $metadata->getColumnNames($tyresTableName);
        unset($tyresFields[array_search('id', $tyresFields)]);
        $insert = $sql->insert($tyresTableName)->columns($tyresFields);
        foreach ($tmpTyres as $tmpTyre){
            if ($tmpTyre->modelId == 0)
                continue;
            $insert->values(array_intersect_key(get_object_vars($tmpTyre), array_flip($tyresFields)));
            $insertSql = $sql->getSqlStringForSqlObject($insert).' ON DUPLICATE KEY UPDATE id = id ';
            $dbAdapter->query($insertSql, $dbAdapter::QUERY_MODE_EXECUTE);
        }
        $dbAdapter->getDriver()->getConnection()->commit();
        
        
        $keyTyreId = [];
        foreach ($this->getTyres() as $tyre){
            $keyTyreId[sprintf($this->uniqTyreMask, 
                $tyre->modelId, 
                $tyre->width, 
                $tyre->height, 
                $tyre->diameter, 
                $tyre->load, 
                $tyre->speed, 
                $tyre->spikes, 
                $tyre->XL, 
                $tyre->RFT)] = $tyre->id;
        }
        
        $dbAdapter->getDriver()->getConnection()->beginTransaction();
        $pricesFields = $metadata->getColumnNames($pricesTableName);
        $insert = $sql->insert($pricesTableName)->columns($pricesFields);
        $deleteIds = [];
        foreach ($tmpTyres as $tmpTyre){
            $key = sprintf($this->uniqTyreMask, 
                $tmpTyre->modelId, 
                $tmpTyre->width, 
                $tmpTyre->height, 
                $tmpTyre->diameter, 
                $tmpTyre->load, 
                $tmpTyre->speed, 
                $tmpTyre->spikes, 
                $tmpTyre->XL, 
                $tmpTyre->RFT);
            if (!isset($keyTyreId[$key]))
                continue;
            $tyreId = $keyTyreId[$key];
            $tmpTyre->tyreId = $tyreId;
            $insert->values(array_intersect_key(get_object_vars($tmpTyre), array_flip($pricesFields)));
            $insertSql = $sql->getSqlStringForSqlObject($insert).' ON DUPLICATE KEY UPDATE ';
            $upd = array();
            foreach ($pricesFields as $priceField) 
                $upd[] = sprintf('%1$s = VALUES(%1$s)', $platform->quoteIdentifier($priceField));
            $insertSql .= implode(', ', $upd);

            $dbAdapter->query($insertSql, $dbAdapter::QUERY_MODE_EXECUTE);
            $deleteIds[] = $tmpTyre->id;
            
        }
        
        //удалим обработанное
        if (count($deleteIds) > 0) {
            $delete = $sql->delete($this->tmp_table);
            $delete->where->in('id', $deleteIds);
            $dbAdapter->query($sql->getSqlStringForSqlObject($delete), $dbAdapter::QUERY_MODE_EXECUTE);
        }
            
        $dbAdapter->getDriver()->getConnection()->commit();
    }
    
    private function getTyres(){
        return $this->getServiceLocator()->get('TyresModelTyreTable')->getTyres();
    }
}
