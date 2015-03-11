<?php
/*************************************************************************************/
/*                                                                                   */
/*      Thelia 1 Database Importation Tool                                           */
/*                                                                                   */
/*      Copyright (c) CQFDev                                                         */
/*      email : contact@cqfdev.fr                                                    */
/*      web : http://www.cqfdev.fr                                                   */
/*                                                                                   */
/*      This program is free software; you can redistribute it and/or modify         */
/*      it under the terms of the GNU General Public License as published by         */
/*      the Free Software Foundation; either version 3 of the License                */
/*                                                                                   */
/*      This program is distributed in the hope that it will be useful,              */
/*      but WITHOUT ANY WARRANTY; without even the implied warranty of               */
/*      MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the                */
/*      GNU General Public License for more details.                                 */
/*                                                                                   */
/*      You should have received a copy of the GNU General Public License            */
/*	    along with this program. If not, see <http://www.gnu.org/licenses/>.         */
/*                                                                                   */
/*************************************************************************************/

namespace ImportT1\Import;

use ImportT1\Import\Media\FolderDocumentImport;
use ImportT1\Import\Media\FolderImageImport;
use ImportT1\Model\Db;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Folder\FolderCreateEvent;
use Thelia\Core\Event\Folder\FolderUpdateEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Event\UpdatePositionEvent;
use Thelia\Log\Tlog;
use Thelia\Model\ContentQuery;
use Thelia\Model\FolderDocumentQuery;
use Thelia\Model\FolderImageQuery;
use Thelia\Model\FolderQuery;

class FoldersImport extends BaseImport
{

    private $fld_corresp;

    public function __construct(EventDispatcherInterface $dispatcher, Db $t1db)
    {

        parent::__construct($dispatcher, $t1db);

        $this->fld_corresp = new CorrespondanceTable(CorrespondanceTable::FOLDERS, $this->t1db);
    }

    public function getChunkSize()
    {
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
                sprintf(
                    "select * from dossier order by parent asc limit %d, %d",
                    intval($startRecord),
                    $this->getChunkSize()
                )
            );

        $image_import = new FolderImageImport($this->dispatcher, $this->t1db);
        $document_import = new FolderDocumentImport($this->dispatcher, $this->t1db);

        while ($hdl && $dossier = $this->t1db->fetch_object($hdl)) {

            Tlog::getInstance()->info("Processing T1 folder ID $dossier->id, parent: $dossier->parent.");

            $count++;

            try {
                $this->fld_corresp->getT2($dossier->id);

                Tlog::getInstance()->warning("Folder ID=$dossier->id already imported.");

                continue;
            } catch (ImportException $ex) {
                // Okay, the dossier was not imported.
            }

            try {

                $event = new FolderCreateEvent();

                $idx = 0;

                $descs = $this->t1db
                    ->query_list(
                        "select * from dossierdesc where dossier = ? order by lang asc",
                        array(
                            $dossier->id
                        )
                    );

                foreach ($descs as $objdesc) {

                    $lang = $this->getT2Lang($objdesc->lang);

                    // A title is required to create the rewritten URL
                    if (empty($objdesc->titre)) {
                        $objdesc->titre = sprintf(
                            "Untitled-%d-%s",
                            $objdesc->id,
                            $lang->getCode()
                        );
                    }

                    $parent = $dossier->parent > 0 ? $dossier->parent + 1000000 : 0;

                    if ($idx == 0) {
                        $event
                            ->setLocale($lang->getLocale())
                            ->setTitle($objdesc->titre)
                            ->setParent($parent) // Will be corrected later
                            ->setVisible($dossier->ligne == 1 ? true : false);

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
                    }

                    // Update the newly created folder
                    $update_event = new FolderUpdateEvent($folder_id);

                    $update_event
                        ->setTitle($objdesc->titre)
                        ->setParent($parent) // Will be corrected later
                        ->setLocale($lang->getLocale())
                        ->setVisible($dossier->ligne == 1 ? true : false)
                        ->setChapo($objdesc->chapo)
                        ->setDescription($objdesc->description)
                        ->setPostscriptum($objdesc->postscriptum);

                    $this->dispatcher->dispatch(TheliaEvents::FOLDER_UPDATE, $update_event);

                    // Update the rewritten URL, if one was defined
                    $this->updateRewrittenUrl(
                        $event->getFolder(),
                        $lang->getLocale(),
                        $objdesc->lang,
                        "dossier",
                        "%id_dossier=$dossier->id"
                    );

                    $idx++;
                }
            } catch (\Exception $ex) {

                Tlog::getInstance()->addError("Failed to import folder ID=$dossier->id: ", $ex->getMessage());

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

            Tlog::getInstance()->addInfo("Searching T1 parent $t1_parent");

            if ($t1_parent > 0) {
                try {
                    $obj->setParent($this->fld_corresp->getT2($t1_parent))->save();
                }
                catch (\Exception $ex) {
                    // Parent does not exists -> delete the T2 folder.
                    $obj->delete();

                    Tlog::getInstance()->addWarning("Deleted folder T2 ID=".$obj->getId().", which has an invalid parent T1 ID=$t1_parent.");
                }
            }
        }
    }
}
