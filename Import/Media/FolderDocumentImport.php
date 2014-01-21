<?php
namespace ImportT1\Import\Media;

use Thelia\Log\Tlog;
use Thelia\Model\FolderDocument;

class FolderDocumentImport extends AbstractMediaImport {

    protected function getMediaModelInstance($t2_object_id) {
        $obj = new FolderDocument();

        return $obj->setFolderId($t2_object_id);
    }

    protected function getMediaList($t1_object_id) {
        return $this->t1db->query_list("select * from document where dossier = ?", array($t1_object_id));
    }

    protected function getMediaDesc($t1_object_id) {
        return $this->t1db->query_list("select * from documentdesc where document = ?", array($t1_object_id));
    }

    public function importMedia($id_dossier, $id_folder) {
        return parent::doImportMedia($id_dossier, $id_folder, "document", "documents".DS."folder");
    }
}