<?php
namespace ImportT1\Import;

use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Event\UpdatePositionEvent;
use Thelia\Log\Tlog;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use ImportT1\Model\Db;
use Symfony\Component\Filesystem\Filesystem;
use Thelia\Model\ContentQuery;
use Thelia\Model\FolderQuery;
use Thelia\Model\FolderDocumentQuery;
use Thelia\Model\FolderImageQuery;
use Thelia\Core\Event\Folder\FolderCreateEvent;
use Thelia\Model\FolderImage;
use Thelia\Model\FolderDocument;
use Thelia\Core\Event\Folder\FolderUpdateEvent;
use ImportT1\Import\Media\FolderDocumentImport;
use ImportT1\Import\Media\FolderImageImport;

class FoldersImport extends BaseImport
{

    private $fld_corresp;


    public function __construct(EventDispatcherInterface $dispatcher, Db $t1db) {

        parent::__construct($dispatcher, $t1db);

        $this->fld_corresp  = new CorrespondanceTable('t1_t2_folders', $this->t1db);
    }

    public function getChunkSize() {
        return 10;
    }

    public function getTotalCount()
    {
        return $this->t1db->num_rows($this->t1db->query("select id from dossier"));
    }

    public function preImport()
    {
        // Delete table before proceeding
        ContentQuery::create()->deleteAll();
        FolderQuery::create()->deleteAll();

        FolderImageQuery::create()->deleteAll();
        FolderDocumentQuery::create()->deleteAll();

        // Create T1 <-> T2 IDs correspondance tables
        $this->fld_corresp->reset();
    }

    public function import($startRecord = 0)
    {
        $count = 0;

        $errors = 0;

        $hdl = $this->t1db
                ->query(
                        sprintf("select * from dossier order by parent asc limit %d, %d", intval($startRecord),
                                $this->getChunkSize()));

        $image_import    = new FolderImageImport($this->dispatcher, $this->t1db);
        $document_import = new FolderDocumentImport($this->dispatcher, $this->t1db);

        while ($hdl && $dossier = $this->t1db->fetch_object($hdl)) {

            $count++;

            try {
                $this->fld_corresp->getT2($dossier->id);

                Tlog::getInstance()->warning("Folder ID=$dossier->id already imported.");

                continue;
            }
            catch (ImportException $ex) {
                // Okay, the dossier was not imported.
            }

            try {

                $event = new FolderCreateEvent();

                $idx = 0;

                $descs = $this->t1db
                        ->query_list("select * from dossierdesc where dossier = ? order by lang asc", array(
                            $dossier->id
                        ));

                foreach ($descs as $objdesc) {

                    $lang = $this->getT2Lang($objdesc->lang);

                    // A title is required to create the rewritten URL
                    if (empty($objdesc->titre)) $objdesc->titre = sprintf("Untitled-%d-%s", $objdesc->id, $lang->getCode());

                    if ($idx == 0) {
                        $event
                            ->setLocale($lang->getLocale())
                            ->setTitle($objdesc->titre)
                            ->setParent(1000000 + $dossier->parent) // Will be corrected later
                            ->setVisible($dossier->ligne == 1 ? true : false)
                        ;

                        $this->dispatcher->dispatch(TheliaEvents::FOLDER_CREATE, $event);

                        $folder_id = $event->getFolder()->getId();

                        Tlog::getInstance()->info("Created folder $folder_id from $objdesc->titre ($dossier->id)");

                        // Update position
                        $update_position_event = new UpdatePositionEvent($folder_id,
                                UpdatePositionEvent::POSITION_ABSOLUTE, $dossier->classement);

                        $this->dispatcher->dispatch(TheliaEvents::FOLDER_UPDATE_POSITION, $update_position_event);

                        $this->fld_corresp->addEntry($dossier->id, $folder_id);

                        // Import images and documents
                        // ---------------------------

                        $image_import->importMedia($dossier->id, $folder_id);
                        $document_import->importMedia($dossier->id, $folder_id);

                        // Update the rewritten URL, if one was defined
                        $this->updateRewrittenUrl($event->getFolder(), $lang->getLocale(), $objdesc->lang, "dossier", "id_dossier=$dossier->id");
                    }

                    // Update the newly created folder
                    $update_event = new FolderUpdateEvent($folder_id);

                    $update_event
                        ->setTitle($objdesc->titre)
                        ->setParent(1000000 + $dossier->parent) // Will be corrected later
                        ->setLocale($lang->getLocale())
                        ->setVisible($dossier->ligne == 1 ? true : false)
                        ->setChapo($objdesc->chapo)
                        ->setDescription($objdesc->description)
                        ->setPostscriptum($objdesc->postscriptum)
                    ;

                    $this->dispatcher->dispatch(TheliaEvents::FOLDER_UPDATE, $update_event);

                    $idx++;
                }
            }
            catch (ImportException $ex) {

                Tlog::getInstance()->addError("Failed to create folder: ", $ex->getMessage());

                $errors++;
            }
        }

        return new ImportChunkResult($count, $errors);
    }

    public function postImport()
    {
        // Fix parent IDs for each object, which are still T1 parent IDs
        $obj_list = FolderQuery::create()->orderById()->find();

        foreach ($obj_list as $obj) {
            $t1_parent = $obj->getParent() - 1000000;

            if ($t1_parent > 0) $obj->setParent($this->fld_corresp->getT2($t1_parent))->save();
        }
    }
}