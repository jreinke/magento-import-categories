<?php

require_once 'abstract.php';

/**
 * Script for automatic category tree creation.
 *
 * @category    Mage
 * @package     Mage_Shell
 * @author      Johann Reinke
 */
class Mage_Shell_ImportCategories extends Mage_Shell_Abstract
{
    /**
     * This method catches invalid store codes because of Magento throwing an exception with empty message!
     *
     * @return Mage_Core_Model_Store
     */
    protected function _getStore($storeCode)
    {
        try {
            return Mage::app()->getStore($storeCode);
        } catch (Mage_Core_Model_Store_Exception $e) {
            Mage::throwException(sprintf("Could not find store with code '%s'", $storeCode));
        }
    }

    /**
     * Prepares default data of new category with specified name.
     *
     * @param string $name
     * @return array
     */
    protected function _prepareCategoryData($name)
    {
        return array(
            'name'              => trim($name),
            'is_active'         => 1,
            'include_in_menu'   => 1,
            'is_anchor'         => 0,
            'url_key'           => '',
            'description'       => '',
        );
    }

    /**
     * Creates new category.
     *
     * @param int    $parentId
     * @param string $name
     * @param int    $storeId
     * @return Mage_Catalog_Model_Category
     */
    protected function _createCategory($parentId, $name, $storeId)
    {
        $category = Mage::getModel('catalog/category');
        /* @var $category Mage_Catalog_Model_Category */

        $data = $this->_prepareCategoryData($name);
        $parent = Mage::getModel('catalog/category')->load($parentId);

        $category->setData($data)
            ->setAttributeSetId($category->getDefaultAttributeSetId())
            ->setStoreId($storeId)
            ->setPath(implode('/', $parent->getPathIds()))
            ->setParentId($parentId)
            ->save();

        return $category;
    }

    /**
     * Process some stuff before running categories import.
     */
    protected function _beforeProcess()
    {
        // Needed for correct creation of categories url rewrites.
        $processes = Mage::getSingleton('index/indexer')->getProcessesCollection();
        $processes->walk('setMode', array(Mage_Index_Model_Process::MODE_MANUAL));
        $processes->walk('save');
    }

    /**
     * Process some stuff after running categories import.
     */
    protected function _afterProcess()
    {
        $processes = Mage::getSingleton('index/indexer')->getProcessesCollection();
        $processes->walk('setMode', array(Mage_Index_Model_Process::MODE_REAL_TIME));
        $processes->walk('save');

        echo PHP_EOL . 'Reindexing all...' . PHP_EOL;
        $processes->walk('reindexAll');
    }

    /**
     * Displays critical message and exit.
     */
    protected function _fault($msg)
    {
        exit(PHP_EOL . $msg . PHP_EOL);
    }

    /**
     * Main script method that imports all categories from specified CSV file.
     */
    public function run()
    {
        $start = microtime(true);

        if (!$file = $this->getArg('f')) {
            echo $this->usageHelp();
        } else {
            $delimiter = $this->getArg('d') ? $this->getArg('d') : ',';
            $enclosure = $this->getArg('e') ? $this->getArg('e') : '"';

            $file = Mage::getBaseDir() . '/' . $file;
            if (!file_exists($file)) {
                $this->_fault("File $file doest not exists.");
            } elseif (!is_readable($file)) {
                $this->_fault("File $file is not readable.");
            }

            $fh = fopen($file, 'r');
            if (!$fh) {
                $this->_fault("An error occured opening file $file.");
            }

            $collection = Mage::getModel('catalog/category')->getCollection()
                ->addFieldToFilter('level', 2);
            if ($collection->count() > 0) {
                if (!$this->getArg('force')) {
                    $this->_fault('Categories already created. Use --force option to delete old categories automatically.');
                } else {
                    $collection->walk('delete');
                    echo 'Deleted old categories' . PHP_EOL;
                }
            }

            try {
                $line = 0;
                $otherStoreCodes = array();
                $rootId = Mage::app()->getDefaultStoreView()->getRootCategoryId();
                $parentIds = array(0 => $rootId);

                $this->_beforeProcess();

                while ($data = fgetcsv($fh, null, $delimiter, $enclosure)) {
                    $line++;

                    if ($line === 1 && ($this->getArg('t') || empty($data[0]))) {
                        $otherStoreCodes = array_filter($data); // will keep store column number and store code
                        continue;
                    }

                    foreach ($data as $i => $value) {
                        if (!empty($otherStoreCodes) && $i >= min(array_keys($otherStoreCodes))) {
                            break;
                        }
                        $label = trim($value);
                        if (empty($label)) {
                            continue;
                        }
                        $parentId = $parentIds[$i];
                        $category = $this->_createCategory($parentId, $label, 0);
                        $parentIds[$i + 1] = $category->getId();
                        echo str_repeat('--', $i + 1) . ' ' . $label;
                        foreach ($otherStoreCodes as $j => $storeCode) {
                            $label = trim($data[$j]);
                            if (!empty($label)) {
                                $category->setStoreId($this->_getStore($storeCode)->getId())
                                    ->setUrlKey('')
                                    ->setName($label)
                                    ->save();
                                printf(' [%s: %s]', $storeCode, $label);
                            }
                        }
                        echo PHP_EOL;
                    }
                }

                $this->_afterProcess();
            } catch (Exception $e) {
                Mage::logException($e);
                $this->_fault($e->getMessage());
            }
        }

        $end = microtime(true);
        $time = $end - $start;
        echo PHP_EOL;
        echo 'Script Start: ' . date('H:i:s', $start) . PHP_EOL;
        echo 'Script End: ' . date('H:i:s', $end) . PHP_EOL;
        echo 'Duration: ' . number_format($time, 3) . ' sec' . PHP_EOL;
    }

    /**
     * List of available script options.
     *
     * @return string
     */
    public function usageHelp()
    {
        return <<< USAGE
Usage:  php -f import_categories.php -- [options]

  -f            File to import
  -d            Delimiter (default is ,)
  -e            Enclosure (default is ")
  -t            Enable store translations in first line
  --force       Delete old categories and create new ones
  -h            Short alias for help
  help          This help

USAGE;
    }
}

if (php_sapi_name() != 'cli') {
    exit('Run it from cli.');
}

$shell = new Mage_Shell_ImportCategories();
$shell->run();
