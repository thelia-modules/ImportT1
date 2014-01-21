<?php
namespace ImportT1\Import\Media;

use Thelia\Log\Tlog;
use Thelia\Model\ContentDocument;

class ContentDocumentImport extends AbstractMediaImport {

    protected function getMediaModelInstance($t2_object_id) {
        $obj = new ContentDocument();

        return $obj->setContentId($t2_object_id);
    }

    protected function getMediaList($t1_object_id) {
        return $this->t1db->query_list("select * from document where contenu = ?", array($t1_object_id));
    }

    protected function getMediaDesc($t1_object_id) {
        return $this->t1db->query_list("select * from documentdesc where document = ?", array($t1_object_id));
    }

    public function importMedia($id_contenu, $id_content) {
        return parent::doImportMedia($id_contenu, $id_content, "contenu", "documents".DS."content");
    }
}