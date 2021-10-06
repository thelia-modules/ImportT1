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
use Symfony\Component\HttpFoundation\RequestStack;
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
use Thelia\Model\Category;
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
    private $product_corresp;
    private $tpl_corresp;
    private $tax_corresp;
    private $cat_corresp;
    private $feat_corresp;
    private $feat_av_corresp;
    private $attr_corresp;
    private $attr_av_corresp;
    private $content_corresp;
    private $requestStack;

    public function __construct(EventDispatcherInterface $dispatcher, Db $t1db, RequestStack $requestStack)
    {

        parent::__construct($dispatcher, $t1db, $requestStack->getCurrentRequest()->getSession());

        $this->requestStack = $requestStack;

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
        return 5;
    }

    /**
     * @return mixed
     * @throws \Exception
     */
    public function getTotalCount()
    {
        return $this->t1db->num_rows($this->t1db->query("select id from produit"));
    }

    /**
     * @throws \Propel\Runtime\Exception\PropelException
     */
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

    /**
     * @param int $startRecord
     * @return ImportChunkResult|void
     * @throws ImportException
     * @throws \Exception
     */
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

        $image_import = new ProductImageImport($this->dispatcher, $this->t1db, $this->requestStack->getCurrentRequest()->getSession());
        $document_import = new ProductDocumentImport($this->dispatcher, $this->t1db, $this->requestStack->getCurrentRequest()->getSession());

        while ($hdl && $produit = $this->t1db->fetch_object($hdl)) {
            $count++;

            // Put contents on the root folder in a special folder
            if ($produit->rubrique == 0) {
                try {
                    $this->cat_corresp->getT2($produit->rubrique);

                } catch (\Exception $ex) {
                    // Create the '0' folder
                    $root = new Category();

                    $root
                        ->setParent(0)
                        ->setVisible(true)
                        ->setLocale('fr_FR')
                        ->setTitle("Rubrique racine Thelia 1")
                        ->setLocale('en_US')
                        ->setTitle("Thelia 1 root category")
                        ->setDescription("")
                        ->setChapo("")
                        ->setPostscriptum("")
                        ->save();

                    Tlog::getInstance()->warning("Created pseudo-root category to store products at root level");

                    $this->cat_corresp->addEntry(0, $root->getId());
                }
            }

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
                    // Check if the product ref is not already defined, and create a new one if it's the case.
                    $origRef = $destRef = $produit->ref;
                    $refIdx = 1;

                    while (null !== $dupProd = ProductQuery::create()->findOneByRef($destRef)) {
                        Tlog::getInstance()->warning("Duplicate product reference: '$destRef', generating alternate reference.");

                        $destRef = sprintf('%s-%d', $origRef, $refIdx++);
                    }

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

                    $product_id = 0;

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
                                ->setRef($destRef)
                                ->setLocale($lang->getLocale())
                                ->setTitle($objdesc->titre)
                                ->setDefaultCategory($this->cat_corresp->getT2($produit->rubrique))
                                ->setVisible($produit->ligne == 1 ? true : false)
                                ->setBasePrice($produit->prix)
                                ->setBaseWeight($produit->poids)
                                ->setTaxRuleId($this->tax_corresp->getT2(1000 * $produit->tva))
                                ->setCurrencyId($this->getT2Currency()->getId());

                            $this->dispatcher->dispatch($event, TheliaEvents::PRODUCT_CREATE);

                            $product_id = $event->getProduct()->getId();

                            // Set the product template (resets the product default price)
                            // -----------------------------------------------------------

                            try {
                                $pste = new ProductSetTemplateEvent(
                                    $event->getProduct(),
                                    $this->tpl_corresp->getT2($produit->rubrique),
                                    $this->getT2Currency()->getId()
                                );

                                $this->dispatcher->dispatch($pste, TheliaEvents::PRODUCT_SET_TEMPLATE);
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
                                $create_pse_event,
                                TheliaEvents::PRODUCT_ADD_PRODUCT_SALE_ELEMENT
                            );

                            $update_pse_event = new ProductSaleElementUpdateEvent(
                                $event->getProduct(),
                                $create_pse_event->getProductSaleElement()->getId()
                            );

                            $update_pse_event
                                ->setReference($destRef)
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
                                $update_pse_event,
                                TheliaEvents::PRODUCT_UPDATE_PRODUCT_SALE_ELEMENT
                            );

                            // Update position
                            // ---------------

                            $update_position_event = new UpdatePositionEvent(
                                $product_id,
                                UpdatePositionEvent::POSITION_ABSOLUTE,
                                $produit->classement
                            );

                            $this->dispatcher->dispatch($update_position_event, TheliaEvents::PRODUCT_UPDATE_POSITION);

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

                                    $this->dispatcher->dispatch($content_event, TheliaEvents::PRODUCT_ADD_CONTENT);
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
                                    if (intval($caracval->caracdisp) != 0) {
                                        $feature_value = $this->feat_av_corresp->getT2($caracval->caracdisp);
                                        $is_text = false;
                                    } elseif ($caracval->valeur != '') {
                                        $feature_value = $caracval->valeur;
                                        $is_text = true;
                                    } else {
                                        continue;
                                    }


                                    $feature_value_event = new FeatureProductUpdateEvent(
                                        $product_id,
                                        $this->feat_corresp->getT2($caracval->caracteristique),
                                        $feature_value,
                                        $is_text
                                    );

                                    if ($is_text) {
                                        $feature_value_event->setLocale($lang->getLocale());
                                    }

                                    $this->dispatcher->dispatch(
                                        $feature_value_event,
                                        TheliaEvents::PRODUCT_FEATURE_UPDATE_VALUE
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
                                        "select * from stock where declidisp=? and produit=?",
                                        array($declidisp->id, $produit->id)
                                    );
                                    if ($stock === false) {
                                        continue;
                                    }

                                    try {
                                        $pse_create_event = new ProductSaleElementCreateEvent(
                                            $event->getProduct(),
                                            array($this->attr_av_corresp->getT2($stock->declidisp)),
                                            $this->getT2Currency()->getId()
                                        );

                                        $this->dispatcher->dispatch(
                                            $pse_create_event,
                                            TheliaEvents::PRODUCT_ADD_PRODUCT_SALE_ELEMENT
                                        );

                                        $pse_update_event = new ProductSaleElementUpdateEvent(
                                            $event->getProduct(),
                                            $pse_create_event->getProductSaleElement()->getId()
                                        );

                                        $pse_update_event
                                            ->setReference($destRef)
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
                                            $pse_update_event,
                                            TheliaEvents::PRODUCT_UPDATE_PRODUCT_SALE_ELEMENT
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
                        }

                        Tlog::getInstance()->info(sprintf("Updating product ID=%d, data for lang %s.", $product_id, $lang->getCode()));

                        // Update the newly created product
                        $update_event = new ProductUpdateEvent($product_id);

                        $update_event
                            ->setRef($destRef)
                            ->setLocale($lang->getLocale())
                            ->setTitle($objdesc->titre)
                            ->setChapo($objdesc->chapo)
                            ->setDescription($objdesc->description)
                            ->setPostscriptum($objdesc->postscriptum)
                            ->setVisible($produit->ligne == 1 ? true : false)
                            ->setDefaultCategory($this->cat_corresp->getT2($produit->rubrique));

                        $this->dispatcher->dispatch($update_event, TheliaEvents::PRODUCT_UPDATE);

                        // Update the rewritten URL, if one was defined
                        $this->updateRewrittenUrl(
                            $event->getProduct(),
                            $lang->getLocale(),
                            $objdesc->lang,
                            "produit",
                            "%id_produit=$produit->id&id_rubrique=$produit->rubrique%",
                            $produit,
                            $objdesc
                        );

                        $idx++;
                    }
                } catch (\Exception $ex) {
                    Tlog::getInstance()->addError("Failed to import product ID=$produit->id: ", $ex->getMessage());

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

    protected function makeT1RewrittenUrl($t1Object, $t1descObj, $idLangT1)
    {
        // T1 URL form :
        // boutique-rangements-pour-argenterie_125_coffrets-et-ecrins-pour-couverts_menageres-table-completes_ecrin-menagere-49-places-table__e02.html
        //
        // TitreRubrique1_IdRubriqueProd_TitreRubrique2_TitreRubrique3_ ... TitreRubriqueProd_ProductTitle__ProductRef.html
        $idRubrique = $origRubrique = $t1Object->rubrique;

        $rubriques = [];

        $idx = 0;

        while ($idRubrique > 0 && $idx++ < 20) {
            $rub = $this->t1db->query_obj("select rd.titre, r.id, r.parent from rubrique r LEFT JOIN rubriquedesc rd on rd.rubrique = r.id where rd.rubrique=? and lang=?",
                [ $idRubrique,  $idLangT1 ]
            );

            $rubriques[] = $rub->titre;

            $idRubrique = $rub->parent;
        }

        $rubriques = array_reverse($rubriques);

        $url = array_shift($rubriques) . '_' . $origRubrique . '_';

        foreach ($rubriques as $rubrique) {
            $url .= $rubrique . '_';
        }

        $url .= $t1descObj->titre . '__' . $t1Object->ref;

        $url = $this->eregurl($url);

        Tlog::getInstance()->addInfo("Thelia 1 product URL generated: $url");

        return $url;
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

        $first = true;

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


            $this->dispatcher->dispatch($taxEvent, TheliaEvents::TAX_CREATE);

            $taxEvent->setId($taxEvent->getTax()->getId());

            Tlog::getInstance()->info("Created tax ID=" . $taxEvent->getTax()->getId() . " for TVA $taux_tva->tva");

            for ($idx = 1; $idx < count($langs); $idx++) {
                $taxEvent
                    ->setLocale($langs[$idx]->getLocale())
                    ->setTitle("TVA $taux_tva->tva%");

                $this->dispatcher->dispatch($taxEvent, TheliaEvents::TAX_UPDATE);
            }

            $taxRuleEvent = new TaxRuleEvent();

            $taxRuleEvent
                ->setLocale($langs[0]->getLocale())
                ->setTitle("Tax rule for TVA $taux_tva->tva%")
                ->setDescription("This tax rule was created from Thelia 1 using TVA $taux_tva->tva%")
                ->setCountryList(array($defaultCountry->getId()))
                ->setTaxList(json_encode(array($taxEvent->getTax()->getId())))
            ;

            $this->dispatcher->dispatch($taxRuleEvent, TheliaEvents::TAX_RULE_CREATE);

            $taxRuleEvent->setId($taxRuleEvent->getTaxRule()->getId());

            $this->dispatcher->dispatch($taxRuleEvent, TheliaEvents::TAX_RULE_TAXES_UPDATE);

            Tlog::getInstance()->info(
                "Created tax rule ID=" . $taxRuleEvent->getTaxRule()->getId() . " for TVA $taux_tva->tva"
            );

            for ($idx = 1; $idx < count($langs); $idx++) {
                $taxRuleEvent
                    ->setLocale($langs[$idx]->getLocale())
                    ->setTitle("Tax rule for TVA $taux_tva->tva%");

                $this->dispatcher->dispatch($taxRuleEvent, TheliaEvents::TAX_RULE_UPDATE);
            }

            if ($first) {
                // Set the first created tax rule as the default tax.
                $this->dispatcher->dispatch($taxRuleEvent, TheliaEvents::TAX_RULE_SET_DEFAULT);
            }

            $this->tax_corresp->addEntry(1000 * $taux_tva->tva, $taxRuleEvent->getTaxRule()->getId());

            $first = false;
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

                $this->dispatcher->dispatch($accessory_event, TheliaEvents::PRODUCT_ADD_ACCESSORY);
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
