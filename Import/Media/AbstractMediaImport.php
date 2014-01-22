<?php
namespace ImportT1\Import\Media;

use Thelia\Log\Tlog;
use ImportT1\Import\BaseImport;
use Symfony\Component\Filesystem\Filesystem;

abstract class AbstractMediaImport extends BaseImport {

    protected abstract function getMediaModelInstance($t2_object_id);

    protected abstract function getMediaList($t1_object_id);

    protected abstract function getMediaDesc($t1_object_id);

    public function doImportMedia($t1_object_id, $t2_object_id, $client_path, $local_path) {

        if ($this->t1db->hasClientPath()) {
            $fs = new Filesystem();

            $list = $this->getMediaList($t1_object_id);

            foreach($list as $item) {

                $src_path = $this->t1db->getClientPath() .DS . $client_path .DS.$item->fichier;

                if ($fs->exists($src_path)) {

                    $dst_path = THELIA_LOCAL_DIR."media".DS.$local_path.DS.$item->fichier;

                    $fs->copy($src_path, $dst_path, true);

                    $descs = $this->getMediaDesc($item->id);

                    $obj = $this->getMediaModelInstance($t2_object_id)
                        ->setFile($item->fichier)
                        ->setPosition($item->classement)
                    ;

                    foreach($descs as $desc) {
                        $lang = $this->getT2Lang($desc->lang);

                        $obj
                            ->setLocale($lang->getLocale())
                            ->setTitle($desc->titre)
                            ->setChapo($desc->chapo)
                            ->setDescription($desc->description)
                            ->setPostscriptum('') // Missing in T1
                        ;
                    }

                    $obj->save();
                }
                else {
                    Tlog::getInstance()->addWarning("Failed to find media file $src_path");
                }
            }
        }
    }
}