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

use ImportT1\Import\Media\CategoryDocumentImport;
use ImportT1\Import\Media\CategoryImageImport;
use ImportT1\Model\Db;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Category\CategoryAddContentEvent;
use Thelia\Core\Event\Category\CategoryCreateEvent;
use Thelia\Core\Event\Category\CategoryUpdateEvent;
use Thelia\Core\Event\Template\TemplateCreateEvent;
use Thelia\Core\Event\Template\TemplateUpdateEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Event\UpdatePositionEvent;
use Thelia\Log\Tlog;
use Thelia\Model\AttributeTemplate;
use Thelia\Model\AttributeTemplateQuery;
use Thelia\Model\CategoryAssociatedContentQuery;
use Thelia\Model\CategoryDocumentQuery;
use Thelia\Model\CategoryImageQuery;
use Thelia\Model\CategoryQuery;
use Thelia\Model\FeatureTemplate;
use Thelia\Model\FeatureTemplateQuery;
use Thelia\Model\ProductQuery;
use Thelia\Model\TemplateQuery;

class CategoriesImport extends BaseImport
{

    private $cat_corresp, $attr_corresp, $feat_corresp, $tpl_corresp, $content_corresp;


    public function __construct(EventDispatcherInterface $dispatcher, Db $t1db)
    {

        parent::__construct($dispatcher, $t1db);

        $this->cat_corresp = new CorrespondanceTable(CorrespondanceTable::CATEGORIES, $this->t1db);
        $this->tpl_corresp = new CorrespondanceTable(CorrespondanceTable::TEMPLATES, $this->t1db);

        $this->attr_corresp = new CorrespondanceTable(CorrespondanceTable::ATTRIBUTES, $this->t1db);
        $this->feat_corresp = new CorrespondanceTable(CorrespondanceTable::FEATURES, $this->t1db);

        $this->content_corresp = new CorrespondanceTable(CorrespondanceTable::CONTENTS, $this->t1db);
    }

    public function getChunkSize()
    {
        return 10;
    }

    public function getTotalCount()
    {
        return $this->t1db->num_rows($this->t1db->query("select id from rubrique"));
    }

    public function preImport()
    {
        // Delete table before proceeding
        ProductQuery::create()->deleteAll();
        CategoryQuery::create()->deleteAll();

        FeatureTemplateQuery::create()->deleteAll();
        AttributeTemplateQuery::create()->deleteAll();

        TemplateQuery::create()->deleteAll();

        CategoryImageQuery::create()->deleteAll();
        CategoryDocumentQuery::create()->deleteAll();

        CategoryAssociatedContentQuery::create()->deleteAll();

        // Create T1 <-> T2 IDs correspondance tables
        $this->cat_corresp->reset();
        $this->tpl_corresp->reset();
    }

    public function import($startRecord = 0)
    {

        $count = 0;

        $errors = 0;

        $hdl = $this->t1db
            ->query(
                sprintf(
                    "select * from rubrique order by parent asc limit %d, %d",
                    intval($startRecord),
                    $this->getChunkSize()
                )
            );

        $image_import = new CategoryImageImport($this->dispatcher, $this->t1db);
        $document_import = new CategoryDocumentImport($this->dispatcher, $this->t1db);

        while ($hdl && $rubrique = $this->t1db->fetch_object($hdl)) {

            $count++;

            try {
                $this->cat_corresp->getT2($rubrique->id);

                Tlog::getInstance()->warning("Category ID=$rubrique->id already imported.");

                continue;
            } catch (ImportException $ex) {
                // Okay, the category was not imported.
            }

            try {

                $event = new CategoryCreateEvent();

                $idx = 0;

                $descs = $this->t1db
                    ->query_list(
                        "select * from rubriquedesc where rubrique = ? order by lang asc",
                        array(
                            $rubrique->id
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

                    $parent = $rubrique->parent > 0 ? $rubrique->parent + 1000000 : 0;

                    if ($idx == 0) {
                        $event
                            ->setLocale($lang->getLocale())
                            ->setTitle($objdesc->titre)
                            ->setParent($parent) // Will be corrected later
                            ->setVisible($rubrique->ligne == 1 ? true : false);

                        $this->dispatcher->dispatch(TheliaEvents::CATEGORY_CREATE, $event);

                        $category_id = $event->getCategory()->getId();

                        // Update position
                        // ---------------

                        $update_position_event = new UpdatePositionEvent($category_id,
                            UpdatePositionEvent::POSITION_ABSOLUTE, $rubrique->classement);

                        $this->dispatcher->dispatch(TheliaEvents::CATEGORY_UPDATE_POSITION, $update_position_event);

                        Tlog::getInstance()->info("Created category $category_id from $objdesc->titre ($rubrique->id)");

                        $this->cat_corresp->addEntry($rubrique->id, $category_id);

                        // Create a product template if this categorie has declinaisons and/or caracteristiques
                        // ------------------------------------------------------------------------------------

                        $tpl_update = null;

                        $feature_list = $this->t1db
                            ->query_list("select caracteristique from rubcaracteristique where rubrique=$rubrique->id");

                        $attribute_list = $this->t1db
                            ->query_list("select declinaison from rubdeclinaison where rubrique=$rubrique->id");

                        if (!empty($attribute_list) || !empty($feature_list)) {

                            $tpl_create = new TemplateCreateEvent();

                            $tpl_create->setLocale($lang->getLocale())->setTemplateName(
                                $objdesc->titre . " (generated by import)"
                            );

                            $this->dispatcher->dispatch(TheliaEvents::TEMPLATE_CREATE, $tpl_create);

                            $tpl_id = $tpl_create->getTemplate()->getId();

                            $tpl_update = new TemplateUpdateEvent($tpl_id);

                            foreach ($attribute_list as $attr) {
                                $attribute_template = new AttributeTemplate();

                                $id = $this->attr_corresp->getT2($attr->declinaison);

                                try {
                                    $attribute_template->setAttributeId($id)->setTemplateId($tpl_id)->save();
                                } catch (\Exception $ex) {
                                    Tlog::getInstance()
                                        ->addError(
                                            "Failed to create template attribute for T1 caractéristique $attr->declinaison : ",
                                            $ex->getMessage()
                                        );

                                    $errors++;
                                }
                            }

                            foreach ($feature_list as $feat) {
                                try {
                                    $feature_template = new FeatureTemplate();

                                    $id = $this->feat_corresp->getT2($feat->caracteristique);

                                    $feature_template->setFeatureId($id)->setTemplateId($tpl_id)->save();
                                } catch (\Exception $ex) {
                                    Tlog::getInstance()
                                        ->addError(
                                            "Failed to create template feature for T1 declinaison $feat->caracteristique : ",
                                            $ex->getMessage()
                                        );

                                    $errors++;
                                }
                            }

                            $this->tpl_corresp->addEntry($rubrique->id, $tpl_id);
                        }

                        // Import related content
                        // ----------------------
                        $contents = $this->t1db->query_list(
                            "select * from contenuassoc where objet=? and type=0 order by classement",
                            array($rubrique->id)
                        ); // type: 1 = produit, 0=rubrique

                        foreach ($contents as $content) {

                            try {
                                $content_event = new CategoryAddContentEvent($event->getCategory(
                                ), $this->content_corresp->getT2($content->contenu));

                                $this->dispatcher->dispatch(TheliaEvents::CATEGORY_ADD_CONTENT, $content_event);
                            } catch (\Exception $ex) {
                                Tlog::getInstance()
                                    ->addError(
                                        "Failed to create associated content $content->contenu for category $category_id: ",
                                        $ex->getMessage()
                                    );

                                $errors++;
                            }
                        }

                        // Import images and documents
                        // ---------------------------

                        $image_import->importMedia($rubrique->id, $category_id);
                        $document_import->importMedia($rubrique->id, $category_id);

                        // Update the rewritten URL
                        $this->updateRewrittenUrl(
                            $event->getCategory(),
                            $lang->getLocale(),
                            $objdesc->lang,
                            "rubrique",
                            "id_rubrique=$rubrique->id"
                        );
                    }

                    // Update the newly created category
                    $update_event = new CategoryUpdateEvent($category_id);

                    $update_event
                        ->setTitle($objdesc->titre)
                        ->setParent($parent) // Will be corrected later
                        ->setLocale($lang->getLocale())
                        ->setVisible($rubrique->ligne == 1 ? true : false)
                        ->setChapo($objdesc->chapo)
                        ->setDescription($objdesc->description)
                        ->setPostscriptum($objdesc->postscriptum);

                    $this->dispatcher->dispatch(TheliaEvents::CATEGORY_UPDATE, $update_event);

                    // Create a product template name in this language
                    // -----------------------------------------------

                    if ($tpl_update !== null) {
                        $tpl_update->setLocale($lang->getLocale())->setTemplateName(
                            $objdesc->titre . " (generated by import)"
                        );

                        $this->dispatcher->dispatch(TheliaEvents::TEMPLATE_UPDATE, $tpl_update);
                    }

                    $idx++;
                }
            } catch (ImportException $ex) {

                Tlog::getInstance()->addError("Failed to create category: ", $ex->getMessage());

                $errors++;
            }
        }

        return new ImportChunkResult($count, $errors);
    }

    public function postImport()
    {
        // Fix parent IDs for each categories, which are still T1 parent IDs
        $obj_list = CategoryQuery::create()->orderById()->find();

        foreach ($obj_list as $obj) {
            $t1_parent = $obj->getParent() - 1000000;

            if ($t1_parent > 0) $obj->setParent($this->cat_corresp->getT2($t1_parent))->save();
        }
    }
}