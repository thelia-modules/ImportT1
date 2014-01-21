<?php
namespace ImportT1\Import\Media;

use Thelia\Log\Tlog;
use Thelia\Model\ContentImage;

class ContentImageImport extends AbstractMediaImport {

    protected function getMediaModelInstance($t2_object_id) {
        $obj = new ContentImage();

        return $obj->setContentId($t2_object_id);
    }

    protected function getMediaList($t1_object_id) {
        return $this->t1db->query_list("select * from image where contenu = ?", array($t1_object_id));
    }

    protected function getMediaDesc($t1_object_id) {
        return $this->t1db->query_list("select * from imagedesc where image = ?", array($t1_object_id));
    }

    public function importMedia($id_contenu, $id_content) {
        return parent::doImportMedia($id_contenu, $id_content, "gfx".DS."photos".DS."contenu", "images".DS."content");
    }
}