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

use ImportT1\Import\Media\ContentDocumentImport;
use ImportT1\Import\Media\ContentImageImport;
use ImportT1\Model\Db;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Content\ContentCreateEvent;
use Thelia\Core\Event\Content\ContentUpdateEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Event\UpdatePositionEvent;
use Thelia\Log\Tlog;
use Thelia\Model\ContentDocumentQuery;
use Thelia\Model\ContentImageQuery;
use Thelia\Model\ContentQuery;
use Thelia\Model\Folder;

class ContentsImport extends BaseImport
{
    private $content_corresp;


    public function __construct(EventDispatcherInterface $dispatcher, Db $t1db)
    {

        parent::__construct($dispatcher, $t1db);

        $this->content_corresp = new CorrespondanceTable(CorrespondanceTable::CONTENTS, $this->t1db);

        $this->fld_corresp = new CorrespondanceTable(CorrespondanceTable::FOLDERS, $this->t1db);
    }

    public function getChunkSize()
    {
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
                sprintf(
                    "select * from contenu order by dossier asc limit %d, %d",
                    intval($startRecord),
                    $this->getChunkSize()
                )
            );

        $image_import = new ContentImageImport($this->dispatcher, $this->t1db);
        $document_import = new ContentDocumentImport($this->dispatcher, $this->t1db);

        while ($hdl && $contenu = $this->t1db->fetch_object($hdl)) {

            $count++;

            // Put contents on the root folder in a special folder
            if ($contenu->dossier == 0) {
                try {
                    $dossier = $this->fld_corresp->getT2($contenu->dossier);

                }
                catch (\Exception $ex) {
                    // Create the '0' folder
                    $root = new Folder();

                    $root
                        ->setParent(0)
                        ->setLocale('fr_FR')
                        ->setTitle("Dossier racine Thelia 1")
                        ->setLocale('en_US')
                        ->setTitle("Thelia 1 root folder")
                        ->setDescription("")
                        ->setChapo("")
                        ->setPostscriptum("")
                        ->setVisible(true)
                    ->save();

                    Tlog::getInstance()->warning("Created pseudo-root folder to store contents at root level");

                    $this->fld_corresp->addEntry(0, $root->getId());
                }
            }

            $dossier = $this->fld_corresp->getT2($contenu->dossier);

            if ($dossier > 0) {

                try {
                    $this->content_corresp->getT2($contenu->id);

                    Tlog::getInstance()->warning("Content ID=$contenu->id already imported.");

                    continue;
                } catch (ImportException $ex) {
                    // Okay, the content was not imported.
                }

                try {

                    $event = new ContentCreateEvent();

                    $idx = 0;

                    $descs = $this->t1db
                        ->query_list(
                            "select * from contenudesc where contenu = ? order by lang asc",
                            array(
                                $contenu->id
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

                        if ($idx == 0) {
                            $event
                                ->setLocale($lang->getLocale())
                                ->setTitle($objdesc->titre)
                                ->setDefaultFolder($dossier)
                                ->setVisible($contenu->ligne == 1 ? true : false);

                            $this->dispatcher->dispatch(TheliaEvents::CONTENT_CREATE, $event);

                            $content_id = $event->getContent()->getId();

                            // Update position
                            $update_position_event = new UpdatePositionEvent($content_id,
                                UpdatePositionEvent::POSITION_ABSOLUTE, $contenu->classement);

                            $this->dispatcher->dispatch(TheliaEvents::CONTENT_UPDATE_POSITION, $update_position_event);

                            Tlog::getInstance()->info(
                                "Created content $content_id from $objdesc->titre ($contenu->id)"
                            );

                            $this->content_corresp->addEntry($contenu->id, $content_id);

                            // Import images and documents
                            // ---------------------------

                            $image_import->importMedia($contenu->id, $content_id);
                            $document_import->importMedia($contenu->id, $content_id);
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
                            ->setPostscriptum($objdesc->postscriptum);

                        $this->dispatcher->dispatch(TheliaEvents::CONTENT_UPDATE, $update_event);

                        // Update the rewritten URL, if one was defined
                        $this->updateRewrittenUrl(
                            $event->getContent(),
                            $lang->getLocale(),
                            $objdesc->lang,
                            "contenu",
                            "%id_contenu=$contenu->id&id_dossier=$contenu->dossier%"
                        );

                        $idx++;
                    }
                } catch (\Exception $ex) {

                    Tlog::getInstance()->addError("Failed to import content ID=$contenu->id: ", $ex->getMessage());

                    $errors++;
                }
            } else {
                Tlog::getInstance()->addError(
                    "Cannot import content ID=$contenu->id, which is at root level (e.g., dossier parent = 0)."
                );

                $errors++;
            }
        }

        return new ImportChunkResult($count, $errors);
    }
}