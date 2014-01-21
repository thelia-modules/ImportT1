<?php
namespace ImportT1\Import\Media;

use Thelia\Log\Tlog;
use Thelia\Model\CategoryDocument;

class CategoryDocumentImport extends AbstractMediaImport {

    protected function getMediaModelInstance($t2_object_id) {
        $obj = new CategoryDocument();

        return $obj->setCategoryId($t2_object_id);
    }

    protected function getMediaList($t1_object_id) {
        return $this->t1db->query_list("select * from document where rubrique = ?", array($t1_object_id));
    }

    protected function getMediaDesc($t1_object_id) {
        return $this->t1db->query_list("select * from documentdesc where rubrique = ?", array($t1_object_id));
    }

    public function importMedia($id_rubrique, $id_category) {
        return parent::doImportMedia($id_rubrique, $id_category, "image", "images".DS."category");
    }
}