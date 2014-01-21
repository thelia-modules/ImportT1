<?php
namespace ImportT1\Import\Media;

use Thelia\Log\Tlog;
use Thelia\Model\FolderImage;

class FolderImageImport extends AbstractMediaImport {

    protected function getMediaModelInstance($t2_object_id) {
        $obj = new FolderImage();

        return $obj->setFolderId($t2_object_id);
    }

    protected function getMediaList($t1_object_id) {
        return $this->t1db->query_list("select * from image where dossier = ?", array($t1_object_id));
    }

    protected function getMediaDesc($t1_object_id) {
        return $this->t1db->query_list("select * from imagedesc where image = ?", array($t1_object_id));
    }

    public function importMedia($id_dossier, $id_folder) {
        return parent::doImportMedia($id_dossier, $id_folder, "gfx".DS."photos".DS."dossier", "images".DS."folder");
    }
}