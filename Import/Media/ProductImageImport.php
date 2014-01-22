<?php
namespace ImportT1\Import\Media;

use Thelia\Log\Tlog;
use Thelia\Model\ProductImage;

class ProductImageImport extends AbstractMediaImport {

    protected function getMediaModelInstance($t2_object_id) {
        $obj = new ProductImage();

        return $obj->setProductId($t2_object_id);
    }

    protected function getMediaList($t1_object_id) {
        return $this->t1db->query_list("select * from image where produit = ?", array($t1_object_id));
    }

    protected function getMediaDesc($t1_object_id) {
        return $this->t1db->query_list("select * from imagedesc where image = ?", array($t1_object_id));
    }

    public function importMedia($id_produit, $id_product) {
        return parent::doImportMedia($id_produit, $id_product, "gfx".DS."photos".DS."produit", "images".DS."product");
    }
}