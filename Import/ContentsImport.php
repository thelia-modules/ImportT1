<?php
namespace ImportT1\Import;

use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Event\UpdatePositionEvent;
use Thelia\Log\Tlog;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use ImportT1\Model\Db;
use Symfony\Component\Filesystem\Filesystem;
use Thelia\Model\ContentQuery;
use Thelia\Model\ContentDocumentQuery;
use Thelia\Model\ContentImageQuery;
use Thelia\Core\Event\Content\ContentCreateEvent;
use Thelia\Model\ContentImage;
use Thelia\Model\ContentDocument;
use Thelia\Core\Event\Content\ContentUpdateEvent;
use ImportT1\Import\Media\ContentDocumentImport;
use ImportT1\Import\Media\ContentImageImport;

class ContentsImport extends BaseImport
{
    private $content_corresp;


    public function __construct(EventDispatcherInterface $dispatcher, Db $t1db) {

        parent::__construct($dispatcher, $t1db);

        $this->content_corresp  = new CorrespondanceTable('t1_t2_contents', $this->t1db);

        $this->fld_corresp  = new CorrespondanceTable('t1_t2_folders', $this->t1db);
    }

    public function getChunkSize() {
        return 10;
    }

    public function getTotalCount()
    {
        return $this->t1db->num_rows($this->t1db->query("select id from contenu"));
    }

    public function preImport()
    {
        // Delete table before proceeding
        ContentQuery::create()->deleteAll();
        ContentQuery::create()->deleteAll();

        ContentImageQuery::create()->deleteAll();
        ContentDocumentQuery::create()->deleteAll();

        // Create T1 <-> T2 IDs correspondance tables
        $this->content_corresp->reset();
    }

    public function import($startRecord = 0)
    {
        $count = 0;

        $errors = 0;

        $hdl = $this->t1db
                ->query(
                        sprintf("select * from contenu order by dossier asc limit %d, %d", intval($startRecord),
                                $this->getChunkSize()));

        $image_import    = new ContentImageImport($this->dispatcher, $this->t1db);
        $document_import = new ContentDocumentImport($this->dispatcher, $this->t1db);

        while ($hdl && $contenu = $this->t1db->fetch_object($hdl)) {

            $dossier = $this->fld_corresp->getT2($contenu->dossier);

            if ($dossier > 0) {
                try {

                    $event = new ContentCreateEvent();

                    $idx = 0;

                    $descs = $this->t1db
                            ->query_list("select * from contenudesc where contenu = ? order by lang asc", array(
                                $contenu->id
                            ));

                    foreach ($descs as $objdesc) {

                        $lang = $this->getT2Lang($objdesc->lang);

                        // A title is required to create the rewritten URL
                        if (empty($objdesc->titre)) $objdesc->titre = sprintf("Untitled-%d-%s", $objdesc->id, $lang->getCode());

                        if ($idx == 0) {
                            $event
                                ->setLocale($lang->getLocale())
                                ->setTitle($objdesc->titre)
                                ->setDefaultFolder($dossier)
                                ->setVisible($contenu->ligne == 1 ? true : false)
                            ;

                            $this->dispatcher->dispatch(TheliaEvents::CONTENT_CREATE, $event);

                            $content_id = $event->getContent()->getId();

                            // Update position
                            $update_position_event = new UpdatePositionEvent($content_id,
                                    UpdatePositionEvent::POSITION_ABSOLUTE, $contenu->classement);

                            $this->dispatcher->dispatch(TheliaEvents::CONTENT_UPDATE_POSITION, $update_position_event);

                            Tlog::getInstance()->info("Created content $content_id from $objdesc->titre ($contenu->id)");

                            $this->content_corresp->addEntry($contenu->id, $content_id);

                            // Import images and documents
                            // ---------------------------

                            $image_import->importMedia($contenu->id, $content_id);
                            $document_import->importMedia($contenu->id, $content_id);

                            // Update the rewritten URL, if one was defined
                            $this->updateRewrittenUrl($event->getContent(), $lang->getLocale(), $objdesc->lang, "contenu", "id_contenu=$contenu->id");
                        }

                        // Update the newly created content
                        $update_event = new ContentUpdateEvent($content_id);

                        $update_event
                            ->setTitle($objdesc->titre)
                            ->setDefaultFolder($this->fld_corresp->getT2($contenu->dossier))
                            ->setLocale($lang->getLocale())
                            ->setVisible($contenu->ligne == 1 ? true : false)
                            ->setChapo($objdesc->chapo)
                            ->setDescription($objdesc->description)
                            ->setPostscriptum($objdesc->postscriptum)
                        ;

                        $this->dispatcher->dispatch(TheliaEvents::CONTENT_UPDATE, $update_event);

                        $idx++;
                    }
                }
                catch (ImportException $ex) {

                    Tlog::getInstance()->addError("Failed to create content ID=$contenu->id: ", $ex->getMessage());

                    $errors++;
                }
            }
            else {
                Tlog::getInstance()->addError("Cannot import content ID=$contenu->id, which is at root level (e.g., dossier parent = 0).");

                $errors++;
            }

            $count++;
        }

        return new ImportChunkResult($count, $errors);
    }
}