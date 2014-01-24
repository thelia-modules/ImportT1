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

use ImportT1\Import\Media\ProductDocumentImport;
use ImportT1\Import\Media\ProductImageImport;
use ImportT1\Model\Db;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\FeatureProduct\FeatureProductUpdateEvent;
use Thelia\Core\Event\Product\ProductAddAccessoryEvent;
use Thelia\Core\Event\Product\ProductAddContentEvent;
use Thelia\Core\Event\Product\ProductCreateEvent;
use Thelia\Core\Event\Product\ProductSetTemplateEvent;
use Thelia\Core\Event\Product\ProductUpdateEvent;
use Thelia\Core\Event\ProductSaleElement\ProductSaleElementCreateEvent;
use Thelia\Core\Event\ProductSaleElement\ProductSaleElementUpdateEvent;
use Thelia\Core\Event\Tax\TaxEvent;
use Thelia\Core\Event\Tax\TaxRuleEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Event\UpdatePositionEvent;
use Thelia\Log\Tlog;
use Thelia\Model\CountryQuery;
use Thelia\Model\LangQuery;
use Thelia\Model\ProductDocumentQuery;
use Thelia\Model\ProductImageQuery;
use Thelia\Model\ProductPriceQuery;
use Thelia\Model\ProductQuery;
use Thelia\Model\ProductSaleElementsQuery;
use Thelia\Model\TaxQuery;
use Thelia\Model\TaxRuleQuery;
use Thelia\TaxEngine\TaxType\PricePercentTaxType;

class ProductsImport extends BaseImport
{
    private $product_corresp, $tpl_corresp, $tax_corresp;


    public function __construct(EventDispatcherInterface $dispatcher, Db $t1db)
    {

        parent::__construct($dispatcher, $t1db);

        $this->product_corresp = new CorrespondanceTable(CorrespondanceTable::PRODUCTS, $this->t1db);

        $this->cat_corresp = new CorrespondanceTable(CorrespondanceTable::CATEGORIES, $this->t1db);
        $this->tpl_corresp = new CorrespondanceTable(CorrespondanceTable::TEMPLATES, $this->t1db);
        $this->tax_corresp = new CorrespondanceTable(CorrespondanceTable::TAX, $this->t1db);

        $this->feat_corresp = new CorrespondanceTable(CorrespondanceTable::FEATURES, $this->t1db);
        $this->feat_av_corresp = new CorrespondanceTable(CorrespondanceTable::FEATURES_AV, $this->t1db);

        $this->attr_corresp = new CorrespondanceTable(CorrespondanceTable::ATTRIBUTES, $this->t1db);
        $this->attr_av_corresp = new CorrespondanceTable(CorrespondanceTable::ATTRIBUTES_AV, $this->t1db);

        $this->content_corresp = new CorrespondanceTable(CorrespondanceTable::CONTENTS, $this->t1db);
    }

    public function getChunkSize()
    {
        return 1;
    }

    public function getTotalCount()
    {
        return $this->t1db->num_rows($this->t1db->query("select id from produit"));
    }

    public function preImport()
    {
        // Delete table before proceeding
        ProductQuery::create()->deleteAll();

        ProductImageQuery::create()->deleteAll();
        ProductDocumentQuery::create()->deleteAll();

        TaxRuleQuery::create()->deleteAll();
        TaxQuery::create()->deleteAll();

        ProductSaleElementsQuery::create()->deleteAll();
        ProductPriceQuery::create()->deleteAll();

        // Create T1 <-> T2 IDs correspondance tables
        $this->product_corresp->reset();
        $this->tax_corresp->reset();

        // Importer les taxes
        $this->importTaxes();
    }

    public function import($startRecord = 0)
    {
        $count = 0;

        $errors = 0;

        $hdl = $this->t1db
            ->query(
                sprintf(
                    "select * from produit order by rubrique asc limit %d, %d",
                    intval($startRecord),
                    $this->getChunkSize()
                )
            );

        $image_import = new ProductImageImport($this->dispatcher, $this->t1db);
        $document_import = new ProductDocumentImport($this->dispatcher, $this->t1db);

        while ($hdl && $produit = $this->t1db->fetch_object($hdl)) {

            $count++;

            $rubrique = $this->cat_corresp->getT2($produit->rubrique);

            if ($rubrique > 0) {

                try {
                    $this->product_corresp->getT2($produit->id);

                    Tlog::getInstance()->warning("Product ID=$produit->id already imported.");

                    continue;
                } catch (ImportException $ex) {
                    // Okay, the product was not imported.
                }

                try {

                    $event = new ProductCreateEvent();

                    $idx = 0;

                    $descs = $this->t1db
                        ->query_list(
                            "select * from produitdesc where produit = ? order by lang asc",
                            array(
                                $produit->id
                            )
                        );

                    // Prices should be without axes
                    $produit->prix = $produit->prix / (1 + $produit->tva / 100);
                    $produit->prix2 = $produit->prix2 / (1 + $produit->tva / 100);

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
                                ->setRef($produit->ref)
                                ->setLocale($lang->getLocale())
                                ->setTitle($objdesc->titre)
                                ->setDefaultCategory($this->cat_corresp->getT2($produit->rubrique))
                                ->setVisible($produit->ligne == 1 ? true : false)
                                ->setBasePrice($produit->prix)
                                ->setBaseWeight($produit->poids)
                                ->setTaxRuleId($this->tax_corresp->getT2(1000 * $produit->tva))
                                ->setCurrencyId($this->getT2Currency()->getId());

                            $this->dispatcher->dispatch(TheliaEvents::PRODUCT_CREATE, $event);

                            $product_id = $event->getProduct()->getId();

                            // Set the product template (resets the product default price)
                            // -----------------------------------------------------------

                            try {
                                $pste = new ProductSetTemplateEvent(
                                    $event->getProduct(),
                                    $this->tpl_corresp->getT2($produit->rubrique),
                                    $this->getT2Currency()->getId()
                                );

                                $this->dispatcher->dispatch(TheliaEvents::PRODUCT_SET_TEMPLATE, $pste);
                            } catch (ImportException $ex) {
                                Tlog::getInstance()
                                    ->addWarning(
                                        "No product template was found for product $product_id: ",
                                        $ex->getMessage()
                                    );
                            }

                            // Create the default product sale element, and update it
                            // ------------------------------------------------------

                            $create_pse_event = new ProductSaleElementCreateEvent(
                                $event->getProduct(),
                                array(),
                                $this->getT2Currency()->getId()
                            );

                            $this->dispatcher->dispatch(
                                TheliaEvents::PRODUCT_ADD_PRODUCT_SALE_ELEMENT,
                                $create_pse_event
                            );

                            $update_pse_event = new ProductSaleElementUpdateEvent(
                                $event->getProduct(),
                                $create_pse_event->getProductSaleElement()->getId()
                            );

                            $update_pse_event
                                ->setReference($produit->ref)
                                ->setPrice($produit->prix)
                                ->setCurrencyId($this->getT2Currency()->getId())
                                ->setWeight($produit->poids)
                                ->setQuantity($produit->stock)
                                ->setSalePrice($produit->prix2)
                                ->setOnsale($produit->promo ? true : false)
                                ->setIsnew($produit->nouveaute ? true : false)
                                ->setIsdefault(true)
                                ->setEanCode('')
                                ->setTaxRuleId($this->tax_corresp->getT2(1000 * $produit->tva))
                                ->setFromDefaultCurrency(0);

                            $this->dispatcher->dispatch(
                                TheliaEvents::PRODUCT_UPDATE_PRODUCT_SALE_ELEMENT,
                                $update_pse_event
                            );

                            // Update position
                            // ---------------

                            $update_position_event = new UpdatePositionEvent($product_id,
                                UpdatePositionEvent::POSITION_ABSOLUTE, $produit->classement);

                            $this->dispatcher->dispatch(TheliaEvents::PRODUCT_UPDATE_POSITION, $update_position_event);

                            Tlog::getInstance()->info(
                                "Created product $product_id from $objdesc->titre ($produit->id)"
                            );

                            $this->product_corresp->addEntry($produit->id, $product_id);

                            // Import related content
                            // ----------------------
                            $contents = $this->t1db->query_list(
                                "select * from contenuassoc where objet=? and type=1 order by classement",
                                array($produit->id)
                            ); // type: 1 = produit, 0=rubrique

                            foreach ($contents as $content) {

                                try {
                                    $content_event = new ProductAddContentEvent($event->getProduct(
                                    ), $this->content_corresp->getT2($content->contenu));

                                    $this->dispatcher->dispatch(TheliaEvents::PRODUCT_ADD_CONTENT, $content_event);
                                } catch (\Exception $ex) {
                                    Tlog::getInstance()
                                        ->addError(
                                            "Failed to create associated content $content->contenu for product $product_id: ",
                                            $ex->getMessage()
                                        );

                                    $errors++;
                                }
                            }

                            // Update features (= caracteristiques) values
                            // -------------------------------------------

                            $caracvals = $this->t1db->query_list(
                                "select * from caracval where produit=?",
                                array($produit->id)
                            );

                            foreach ($caracvals as $caracval) {

                                try {

                                    if (intval($caracval->caracdisp) == 0) {
                                        $feature_value = $caracval->valeur;
                                        $is_text = true;
                                    } else {
                                        $feature_value = $this->feat_av_corresp->getT2($caracval->caracdisp);
                                        $is_text = false;
                                    }

                                    $feature_value_event = new FeatureProductUpdateEvent(
                                        $product_id,
                                        $this->feat_corresp->getT2($caracval->caracteristique),
                                        $feature_value,
                                        $is_text
                                    );

                                    $this->dispatcher->dispatch(
                                        TheliaEvents::PRODUCT_FEATURE_UPDATE_VALUE,
                                        $feature_value_event
                                    );
                                } catch (\Exception $ex) {
                                    Tlog::getInstance()
                                        ->addError(
                                            "Failed to update feature value with caracdisp ID=$caracval->caracdisp (value='$caracval->valeur') for product $product_id: ",
                                            $ex->getMessage()
                                        );

                                    $errors++;
                                }
                            }

                            // Update Attributes (= declinaisons) options
                            // ------------------------------------------
                            $rubdecs = $this->t1db->query_list(
                                "
                                                                select
                                                                    declinaison
                                                                from
                                                                    rubdeclinaison rd, declinaison d
                                                                where
                                                                    rd.declinaison=d.id
                                                                and
                                                                    rd.rubrique=?
                                                                 order by
                                                                    d.classement",
                                array($produit->rubrique)
                            );

                            foreach ($rubdecs as $rubdec) {
                                $declidisps = $this->t1db->query_list(
                                    "select id from declidisp where declinaison=?",
                                    array($rubdec->declinaison)
                                );

                                foreach ($declidisps as $declidisp) {

                                    $disabled = $this->t1db->query_list(
                                        "select id from exdecprod where declidisp=? and produit=?",
                                        array($declidisp->id, $produit->id)
                                    );

                                    if (count($disabled) > 0) {
                                        continue;
                                    }

                                    $stock = $this->t1db->query_obj(
                                        "select * from stock where declidisp=?and produit=?",
                                        array($declidisp->id, $produit->id)
                                    );

                                    if ($stock == false) {
                                        continue;
                                    }

                                    try {
                                        $pse_create_event = new ProductSaleElementCreateEvent(
                                            $event->getProduct(),
                                            array($this->attr_av_corresp->getT2($stock->declidisp)),
                                            $this->getT2Currency()->getId()
                                        );

                                        $this->dispatcher->dispatch(
                                            TheliaEvents::PRODUCT_ADD_PRODUCT_SALE_ELEMENT,
                                            $pse_create_event
                                        );

                                        $pse_update_event = new ProductSaleElementUpdateEvent(
                                            $event->getProduct(),
                                            $pse_create_event->getProductSaleElement()->getId()
                                        );

                                        $pse_update_event
                                            ->setReference($produit->ref)
                                            ->setPrice($produit->prix + $stock->surplus)
                                            ->setCurrencyId($this->getT2Currency()->getId())
                                            ->setWeight($produit->poids)
                                            ->setQuantity($stock->valeur)
                                            ->setSalePrice($produit->prix2 + $stock->surplus)
                                            ->setOnsale($produit->promo ? true : false)
                                            ->setIsnew($produit->nouveaute ? true : false)
                                            ->setIsdefault(true)
                                            ->setEanCode('')
                                            ->setTaxRuleId($this->tax_corresp->getT2(1000 * $produit->tva))
                                            ->setFromDefaultCurrency(0);

                                        $this->dispatcher->dispatch(
                                            TheliaEvents::PRODUCT_UPDATE_PRODUCT_SALE_ELEMENT,
                                            $pse_update_event
                                        );
                                    } catch (\Exception $ex) {
                                        Tlog::getInstance()
                                            ->addError(
                                                "Failed to update product sale element value with declidisp ID=$stock->declidisp for product $product_id: ",
                                                $ex->getMessage()
                                            );

                                        $errors++;
                                    }
                                }
                            }

                            // Import images and documents
                            // ---------------------------

                            $image_import->importMedia($produit->id, $product_id);
                            $document_import->importMedia($produit->id, $product_id);

                            // Update the rewritten URL, if one was defined
                            $this->updateRewrittenUrl(
                                $event->getProduct(),
                                $lang->getLocale(),
                                $objdesc->lang,
                                "produit",
                                "id_produit=$produit->id"
                            );
                        }

                        // Update the newly created product
                        $update_event = new ProductUpdateEvent($product_id);

                        $update_event
                            ->setLocale($lang->getLocale())
                            ->setTitle($objdesc->titre)
                            ->setChapo($objdesc->chapo)
                            ->setDescription($objdesc->description)
                            ->setPostscriptum($objdesc->postscriptum)
                            ->setVisible($produit->ligne == 1 ? true : false)
                            ->setDefaultCategory($this->cat_corresp->getT2($produit->rubrique));

                        $this->dispatcher->dispatch(TheliaEvents::PRODUCT_UPDATE, $update_event);

                        $idx++;
                    }
                } catch (\Exception $ex) {

                    Tlog::getInstance()->addError("Failed to create product ID=$produit->id: ", $ex->getMessage());

                    $errors++;
                }
            } else {
                Tlog::getInstance()->addError(
                    "Cannot import product ID=$produit->id, which is at root level (e.g., rubrique parent = 0)."
                );

                $errors++;
            }
        }

        return new ImportChunkResult($count, $errors);
    }

    public function importTaxes()
    {
        $taux_tvas = $this->t1db->query_list("select distinct tva from produit");

        $taux_tvas_vp = $this->t1db->query_list("select distinct tva from venteprod");

        foreach ($taux_tvas_vp as $tvp) {

            $found = false;

            foreach ($taux_tvas as $tv) {
                if ($tvp->tva == $tv->tva) {
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $taux_tvas[] = $tvp;
            }
        }

        $langs = LangQuery::create()->find();

        $defaultCountry = CountryQuery::create()->findOneByByDefault(true);

        foreach ($taux_tvas as $taux_tva) {

            $ppe = new PricePercentTaxType();

            $ppe->setPercentage($taux_tva->tva);

            $taxEvent = new TaxEvent();

            $taxEvent
                ->setLocale($langs[0]->getLocale())
                ->setTitle("TVA $taux_tva->tva%")
                ->setDescription("This tax was imported from Thelia 1 using TVA $taux_tva->tva%")
                ->setType(get_class($ppe))
                ->setRequirements($ppe->getRequirements());

            $this->dispatcher->dispatch(TheliaEvents::TAX_CREATE, $taxEvent);

            $taxEvent->setId($taxEvent->getTax()->getId());

            Tlog::getInstance()->info("Created tax ID=" . $taxEvent->getTax()->getId() . " for TVA $taux_tva->tva");

            for ($idx = 1; $idx < count($langs); $idx++) {
                $taxEvent
                    ->setLocale($langs[$idx]->getLocale())
                    ->setTitle("TVA $taux_tva->tva%");

                $this->dispatcher->dispatch(TheliaEvents::TAX_UPDATE, $taxEvent);
            }

            $taxRuleEvent = new TaxRuleEvent();

            $taxRuleEvent
                ->setLocale($langs[0]->getLocale())
                ->setTitle("Tax rule for TVA $taux_tva->tva%")
                ->setDescription("This tax rule was created from Thelia 1 using TVA $taux_tva->tva%")
                ->setCountryList(array($defaultCountry->getId()))
                ->setTaxList(json_encode(array($taxEvent->getTax()->getId())));

            $this->dispatcher->dispatch(TheliaEvents::TAX_RULE_CREATE, $taxRuleEvent);

            $taxRuleEvent->setId($taxRuleEvent->getTaxRule()->getId());

            $this->dispatcher->dispatch(TheliaEvents::TAX_RULE_TAXES_UPDATE, $taxRuleEvent);

            Tlog::getInstance()->info(
                "Created tax rule ID=" . $taxRuleEvent->getTaxRule()->getId() . " for TVA $taux_tva->tva"
            );

            for ($idx = 1; $idx < count($langs); $idx++) {
                $taxRuleEvent
                    ->setLocale($langs[$idx]->getLocale())
                    ->setTitle("Tax rule for TVA $taux_tva->tva%");

                $this->dispatcher->dispatch(TheliaEvents::TAX_RULE_UPDATE, $taxRuleEvent);
            }

            $this->tax_corresp->addEntry(1000 * $taux_tva->tva, $taxRuleEvent->getTaxRule()->getId());
        }
    }

    public function postImport()
    {

        // Import product Accessories
        // --------------------------

        $accessoires = $this->t1db->query_list(
            "select * from accessoire order by classement"
        );

        foreach ($accessoires as $accessoire) {

            try {
                $product = ProductQuery::create()->findPk($this->product_corresp->getT2($accessoire->produit));

                $accessory_event = new ProductAddAccessoryEvent(
                    $product,
                    $this->product_corresp->getT2($accessoire->accessoire)
                );

                $this->dispatcher->dispatch(TheliaEvents::PRODUCT_ADD_ACCESSORY, $accessory_event);
            } catch (\Exception $ex) {
                Tlog::getInstance()
                    ->addError(
                        "Failed to create product accessory $accessoire->id: ",
                        $ex->getMessage()
                    );
            }
        }
    }
}