<?php
/**
 * @author    Agence Dn'D <magento@dnd.fr>
 * @copyright Copyright (c) 2015 Agence Dn'D (http://www.dnd.fr)
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

class Pimgento_Family_Model_Import extends Pimgento_Core_Model_Import_Abstract
{

    /**
     * @var string
     */
    protected $_code = 'family';

    /**
     * @var int
     */
    protected $_productEntityTypeId = null;

    /**
     * @var int
     */
    protected $_defaultAttributeSetId = null;

    /**
     * Create table (Step 1)
     *
     * @param Pimgento_Core_Model_Task $task
     *
     * @return bool
     */
    public function createTable($task)
    {
        $file = $task->getFile();

        $this->getRequest()->createTableFromFile($this->getCode(), $file);

        return true;
    }

    /**
     * Insert data (Step 2)
     *
     * @param Pimgento_Core_Model_Task $task
     *
     * @return bool
     * @throws Exception
     */
    public function insertData($task)
    {
        $file = $task->getFile();

        $lines = $this->getRequest()->insertDataFromFile($this->getCode(), $file);

        if (!$lines) {
            $task->error(
                Mage::helper('pimgento_family')->__(
                    'No data to insert, verify the file is not empty or CSV configuration is correct'
                )
            );
        }

        $this->deleteExclusion();

        $task->setMessage(
            Mage::helper('pimgento_family')->__('%s lines found', $lines)
        );

        return true;
    }

    /**
     * Match Entity with Code (Step 3)
     *
     * @param Pimgento_Core_Model_Task $task
     *
     * @return bool
     */
    public function matchEntity($task)
    {
        $this->getRequest()->matchEntity($this->getCode(), 'eav/attribute_set', 'attribute_set_id');

        return true;
    }

    /**
     * Insert Family (Step 4)
     *
     * @param Pimgento_Core_Model_Task $task
     *
     * @return bool
     */
    public function insertFamily($task)
    {
        $resource = $this->getResource();
        $adapter  = $this->getAdapter();
        $locale   = Mage::app()->getLocale()->getLocaleCode();

        $parents = $adapter->select()
            ->from(
                $this->getTable(),
                array(
                    'attribute_set_id'   => 'entity_id',
                    'entity_type_id'     => $this->_zde($this->getProductEntityTypeId()),
                    'attribute_set_name' => 'label-' . $locale,
                    'sort_order'         => $this->_zde(1),
                )
            );

        $insert = $adapter->insertFromSelect(
            $parents,
            $resource->getTable('eav/attribute_set'),
            array(),
            Varien_Db_Adapter_Interface::INSERT_ON_DUPLICATE
        );

        $adapter->query($insert);

        return true;
    }

    /**
     * Insert family attribute relations (Step 5)
     *
     * @param Pimgento_Core_Model_Task $task
     *
     * @return bool
     */
    public function insertFamilyAttributeRelations($task) {
        $resource = $this->getResource();
        $adapter = $this->getAdapter();
        $familyAttributeRelationsTable = 'pimgento_family_attribute_relations';

        $adapter->delete($familyAttributeRelationsTable);

        $values = $adapter->select()
            ->from(
                $this->getTable(),
                array(
                    'family_code' => 'code',
                    'attribute_code' => 'attributes'
                )
            );

        $query = $adapter->query($values);

        while ($row = $query->fetch()) {
            $attributes = explode(',', $row['attribute_code']);
            foreach ($attributes as $attribute) {
                $adapter->insert(
                    $familyAttributeRelationsTable,
                    array('family_code' => $row['family_code'], 'attribute_code' => $attribute)
                );
            }
        }

        return true;
    }

    /**
     * Init default Group (Step 6)
     *
     * @param Pimgento_Core_Model_Task $task
     *
     * @return bool
     */
    public function initDefaultGroup($task)
    {
        $resource = $this->getResource();
        $adapter  = $this->getAdapter();

        $family = $adapter->select()
            ->from(
                $this->getTable(),
                array(
                    'entity_id',
                )
            );

        $query = $adapter->query($family);

        $count = 0;

        while (($row = $query->fetch())) {

            $exist = $adapter->fetchOne(
                $adapter->select()
                    ->from($resource->getTable('eav/attribute_group'), array($this->_zde(1)))
                    ->where('attribute_set_id = ?', $row['entity_id'])
                    ->limit(1)
            );

            if (!$exist) {

                $set = Mage::getModel('eav/entity_attribute_set')->load($row['entity_id']);

                if ($set->hasData()) {
                    $set->initFromSkeleton($this->getDefaultAttributSetId())->save();
                    $count++;
                }

            }

        }

        $task->setMessage(Mage::helper('pimgento_attribute')->__('%s family(ies) initialized', $count));

        return true;
    }

    /**
     * Drop table (Step 7)
     *
     * @param Pimgento_Core_Model_Task $task
     *
     * @return bool
     */
    public function dropTable($task)
    {
        $this->getRequest()->dropTable($this->getCode());

        Mage::dispatchEvent('task_executor_drop_table_after', array('task' => $task));

        return true;
    }

    /**
     * get product entity type id
     *
     * @return int
     */
    public function getProductEntityTypeId()
    {
        if ($this->_productEntityTypeId === NULL) {
            $this->_productEntityTypeId = Mage::helper('pimgento_core')->getProductEntityTypeId();
        }
        return $this->_productEntityTypeId;
    }


    /**
     * get default attribute set id
     *
     * @return int
     */
    public function getDefaultAttributSetId()
    {
        if($this->_defaultAttributeSetId === NULL){
            $this->_defaultAttributeSetId = Mage::getSingleton('eav/config')
                ->getEntityType(Mage_Catalog_Model_Product::ENTITY)
                ->getDefaultAttributeSetId();
        }
        return $this->_defaultAttributeSetId;
    }

}