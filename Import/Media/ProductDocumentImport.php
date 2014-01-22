<?php
namespace ImportT1\Import\Media;

use Thelia\Log\Tlog;
use Thelia\Model\ProductDocument;

class ProductDocumentImport extends AbstractMediaImport {

    protected function getMediaModelInstance($t2_object_id) {
        $obj = new ProductDocument();

        return $obj->setProductId($t2_object_id);
    }

    protected function getMediaList($t1_object_id) {
        return $this->t1db->query_list("select * from document where produit = ?", array($t1_object_id));
    }

    protected function getMediaDesc($t1_object_id) {
        return $this->t1db->query_list("select * from documentdesc where document = ?", array($t1_object_id));
    }

    public function importMedia($id_produit, $id_product) {
        return parent::doImportMedia($id_produit, $id_product, "produit", "documents".DS."product");
    }
}