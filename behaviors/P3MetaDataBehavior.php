<?php

/**
 * Class file.
 * @author    Tobias Munk <schmunk@usrbin.de>
 * @link      http://www.phundament.com/
 * @copyright Copyright &copy; 2005-2011 diemeisterei GmbH
 * @license   http://www.phundament.com/license/
 */

/**
 * Meta Data behavior
 * Handles meta data attributes such as
 * <ul>
 * <li>
 * status
 * language
 * owner
 * createdAt
 * createdBy
 * model
 * modifiedAt
 * modifiedBy
 * </lI>
 * <ul>
 * Handles record based permissions
 * checkAccessUpdate
 * checkAccessDelete
 * Handles parent-child relationship between records
 * @author  Tobias Munk <schmunk@usrbin.de>
 * @package p3extensions.behaviors
 * @since   3.0.1
 */
class P3MetaDataBehavior extends CActiveRecordBehavior
{

    /**
     * Name of the relation identifier in the 'parent' model
     * @var string
     */
    public $metaDataRelation;

    /**
     * Name of the relation in the meta data model to the 'parent' model
     * @var string
     */
    public $contentRelation;

    /**
     * Name of the internal meta data parent(-child) relation
     * @var type
     */
    public $parentRelation;

    /**
     * Name of the internal meta data (parent-)child relation
     * @var type
     */
    public $childrenRelation;

    /**
     * @var
     */
    public $superuserRole = 'Superuser';

    private $_children = null;
    private $_parent = null;
    private $_metaDataModel = null;

    const STATUS_DELETED = 0;
    const STATUS_DRAFT   = 10;
    const STATUS_PENDING = 20;
    const STATUS_ACTIVE  = 30;
    const STATUS_LOCKED  = 40;
    const STATUS_HIDDEN  = 50;
    const STATUS_ARCHIVE = 60;

    public function getChildren()
    {
        if ($this->_children === null) {

            $return = array();

            // TODO .... datacheck
            $model = $this->resolveMetaDataModel();
            if ($model === null) {
                Yii::log('Record #' . $this->owner->id . ' has no Meta Data model.');

                return array();
            }

            $criteria            = new CDbCriteria;
            $criteria->condition = 'treeParent_id = ' . $this->owner->id;
            $criteria->order     = "treePosition ASC";
            $owner               = $this->owner;
            $children            = $owner::model()->with($this->metaDataRelation)->findAll($criteria);

            foreach ($children AS $metaModel) {
                $return[] = $metaModel;
            }

            $this->_children = $return;
        }

        return $this->_children;
    }

    public function getParent()
    {
        if ($this->_parent === null) {
            $model = $this->resolveMetaDataModel();
            if ($this->metaDataRelation == '_self_') {
                $result = $model->findByAttributes(array('id' => $this->owner->{$this->metaDataRelation}->treeParent_id));
            }
            else {
                $owner  = $this->owner;
                $result = $owner::model()->findByAttributes(array('id' => $model->treeParent_id));
            }
            $this->_parent = $result;
        }

        return $this->_parent;
    }


    public function beforeFind($event)
    {
        parent::beforeFind($event);

        // exist in console app - disable
        if (Yii::app() instanceof CConsoleApplication) {
            Yii::log('Meta Data behavior omitted in console application.', CLogger::LEVEL_INFO);

            return true;
        }

        //echo get_class($this->owner);
        $criteria = $this->createReadAccessCriteria();
        $this->owner->applyScopes($criteria);
        $this->owner->setDbCriteria($criteria);
    }

    /**
     * Checks permissions in attribute checkAccessDelete and saves meta-data model for afterDelete
     *
     * @param type $event
     *
     * @return type
     */
    public function beforeDelete($event)
    {
        parent::beforeDelete($event);
        if ($this->resolveMetaDataModel() !== null) {
            if ($this->resolveMetaDataModel()->checkAccessDelete && Yii::app()->user->checkAccess($this->resolveMetaDataModel()->checkAccessDelete) === false) {
                throw new CHttpException(403, "You are not authorized to perform this action. Access restricted by P3MetaDataBehavior.");

                return false;
            }
            else {
                if ($this->metaDataRelation !== "_self_") {
                    $this->_metaDataModel = $this->resolveMetaDataModel(); // save model for deletion, should be done automatically eg. in MySQL
                }
            }
        }

        return true;
    }

    /**
     * Deletes meta data record
     *
     * @param type $event
     *
     * @return type
     */
    public function afterDelete($event)
    {
        if ($this->_metaDataModel !== null) {
            $this->_metaDataModel->delete(); // delete, if base record is already deleted
        }
        return true;
    }

    /**
     * Checks permissions in attribute checkAccessUpdate
     *
     * @param type $event
     *
     * @return type
     */
    public
    function beforeSave($event)
    {
        parent::beforeSave($event);

        // exist in console app - no automatic saving
        if (Yii::app() instanceof CConsoleApplication) {
            Yii::log('Meta Data behavior omitted in console application.', CLogger::LEVEL_INFO);

            return true;
        }

        if ($this->resolveMetaDataModel() !== null && $this->resolveMetaDataModel()->checkAccessUpdate) {
            if (Yii::app()->user->checkAccess($this->resolveMetaDataModel()->checkAccessUpdate) === false) {
                throw new CHttpException(403, "You are not authorized to perform this action. Access restricted by P3MetaDataBehavior.");

                return false;
            }
        }

        return true;
    }

    /**
     * Creates meta data for new records or updates modified attributes when saving
     *
     * @param type $event
     *
     * @return type
     */
    public
    function afterSave($event)
    {
        parent::afterSave($event);

        // do not auto-create meta data information for meta data table itself (recursion).
        if ($this->metaDataRelation == '_self_') {
            return true;
        }

        // exist in console app - no automatic saving
        if (Yii::app() instanceof CConsoleApplication) {
            Yii::log('Meta Data behavior omitted in console application.', CLogger::LEVEL_INFO);
            $userId      = 1;
            $primaryRole = null;
        }
        else {
            $userId      = Yii::app()->user->id;
            $primaryRole = key(Yii::app()->authManager->getRoles(Yii::app()->user->id));
        }

        // create new meta data record or just update modifiedBy/At columns
        if ($this->resolveMetaDataModel() === null) {
            $metaClassName     = $this->owner->getActiveRelation($this->metaDataRelation)->className;
            $metaModel         = new $metaClassName;
            $metaModel->id     = $this->owner->id;
            $metaModel->status = self::STATUS_ACTIVE;
            //$metaModel->language = Yii::app()->language;
            $metaModel->language = '_ALL';
            $metaModel->owner    = $userId;
            //$metaModel->checkAccessUpdate = $primaryRole; // removed setting it per default - TODO: config option
            //$metaModel->checkAccessDelete = $primaryRole; // removed setting it per default - TODO: config option
            $metaModel->createdAt = date('Y-m-d H:i:s');
            $metaModel->createdBy = $userId;
            $metaModel->guid      = sprintf('%04X%04X-%04X-%04X-%04X-%04X%04X%04X', mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(16384, 20479), mt_rand(32768, 49151), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535));
            $metaModel->model     = get_class($this->owner);
        }
        else {
            $metaModel             = $this->resolveMetaDataModel();
            $metaModel->modifiedAt = date('Y-m-d H:i:s');
            $metaModel->modifiedBy = $userId;
        }
        $metaModel->save();

        return true;
    }

    /**
     * Finds meta data model from settings
     * @return type
     * @throws CException
     */
    private
    function resolveMetaDataModel()
    {
        if (!$this->metaDataRelation) {
            throw new CException("Attribute 'metaDataRelation' for model '" . get_class($this->owner) . "' not set.");
        }
        elseif ($this->metaDataRelation == "_self_") {
            // special case for meta data tables
            return $this->owner;
        }
        elseif (strpos($this->metaDataRelation, ".")) {
            // if there's a dot in the name, build the return value in object notation
            $parts  = explode(".", $this->metaDataRelation);
            $return = $this->owner;
            foreach ($parts AS $part) {
                $return = $return->$part;
            }

            return $return;
        }
        else {
            // manual setting
            return $this->owner->{$this->metaDataRelation};
        }
    }

    /**
     *Creates a CDbCriteria with restrics read access by meta data settings
     * @return \CDbCriteria
     */
    private
    function createReadAccessCriteria()
    {
        $criteria = new CDbCriteria;

        // do not apply filter for superuser
        if (!Yii::app()->user->checkAccess($this->superuserRole)) {
            if ($this->owner->metaDataRelation != "_self_") {
                $criteria->with = $this->owner->metaDataRelation;
                $tablePrefix    = $this->owner->metaDataRelation;
            }
            else {
                $tablePrefix = $this->owner->getTableAlias();
            }

            $checkAccessRoles = "";
            if (!Yii::app()->user->isGuest) {
                foreach (Yii::app()->authManager->getRoles(Yii::app()->user->id) AS $role) {
                    $checkAccessRoles .= $tablePrefix . ".checkAccessRead = '" . $role->name . "' OR ";
                }
            }
            else {
                $checkAccessRoles .= $tablePrefix . ".checkAccessRead = 'Guest' OR ";
            }
            $criteria->condition = $checkAccessRoles . " " . $tablePrefix . ".checkAccessRead IS NULL";
        }

        return $criteria;
    }

}

?>