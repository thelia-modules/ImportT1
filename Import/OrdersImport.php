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

use ImportT1\Model\Db;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Propel;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Condition\ConditionCollection;
use Thelia\Log\Tlog;
use Thelia\Model\CustomerQuery;
use Thelia\Model\Map\OrderTableMap;
use Thelia\Model\Module;
use Thelia\Model\ModuleQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderAddress;
use Thelia\Model\OrderCoupon;
use Thelia\Model\OrderProduct;
use Thelia\Model\OrderProductTax;
use Thelia\Model\OrderQuery;
use Thelia\Model\OrderStatus;
use Thelia\Model\OrderStatusQuery;
use Thelia\Module\BaseModule;

class OrdersImport extends BaseImport
{

    private $product_corresp, $attr_corresp, $tax_corresp, $order_corresp, $cust_corresp;


    public function __construct(EventDispatcherInterface $dispatcher, Db $t1db)
    {

        parent::__construct($dispatcher, $t1db);

        $this->product_corresp = new CorrespondanceTable(CorrespondanceTable::PRODUCTS, $this->t1db);
        $this->attr_corresp = new CorrespondanceTable(CorrespondanceTable::ATTRIBUTES, $this->t1db);
        $this->tax_corresp = new CorrespondanceTable(CorrespondanceTable::TAX, $this->t1db);
        $this->order_corresp = new CorrespondanceTable(CorrespondanceTable::ORDERS, $this->t1db);
        $this->cust_corresp = new CorrespondanceTable(CorrespondanceTable::CUSTOMERS, $this->t1db);

    }

    public function getChunkSize()
    {
        return 100;
    }
    public function getTotalCount()
    {
        return $this->t1db->num_rows($this->t1db->query("select id from commande"));
    }

    public function preImport()
    {
        // Delete table before proceeding
        OrderQuery::create()->deleteAll();

        // Delete custom order status
        OrderStatusQuery::create()->filterById(5, Criteria::GREATER_THAN)->delete();

        // Create T1 <-> T2 IDs correspondance tables
        $this->order_corresp->reset();

        $this->importCustomOrderStatus();
    }

    public function importCustomOrderStatus()
    {
        // Assume Thelia status are the same up to ID #5
        $hdl = $this->t1db->query("select * from statut where id > ?", array(5));

        while ($hdl && $statut = $this->t1db->fetch_object($hdl)) {

            $ct = new OrderStatus();

            $descs = $this->t1db->query_list(
                "select * from statutdesc where statut = ? order by lang asc",
                array($statut->id)
            );

            foreach ($descs as $desc) {

                $lang = $this->getT2Lang($desc->lang);

                $ct
                    ->setCode(strtolower($statut->nom))
                    ->setLocale($lang->getLocale())
                    ->setTitle($desc->titre)
                    ->setChapo($desc->chapo)
                    ->setDescription($desc->description)
                    ->save();
            }
        }
    }

    public function import($startRecord = 0)
    {

        $count = 0;

        $errors = 0;

        $hdl = $this->t1db
            ->query(
                sprintf(
                    "select * from commande order by id asc limit %d, %d",
                    intval($startRecord),
                    $this->getChunkSize()
                )
            );

        while ($hdl && $commande = $this->t1db->fetch_object($hdl)) {

            $count++;

            try {
                $this->order_corresp->getT2($commande->id);

                Tlog::getInstance()->warning("Order ID=$commande->id already imported.");

                continue;
            } catch (ImportException $ex) {
                // Okay, the order was not imported.
            }

            try {

                if (null === $customer = CustomerQuery::create()->findPk(
                        $this->cust_corresp->getT2($commande->client)
                    )
                ) {
                    throw new ImportException("Failed to find customer ID=$commande->client");
                }

                if (null === $status = OrderStatusQuery::create()->findPk($commande->statut)) {
                    throw new ImportException("Failed to find order status ID=$commande->statut");
                }

                // Create invoice address
                if (false == $adr_livr = $this->t1db->query_obj(
                        "select * from venteadr where id=?",
                        array($commande->adrlivr)
                    )
                ) {
                    throw new ImportException("Failed to find delivery adresse ID=$commande->adrlivr");
                }

                // Create invoice address
                if (false == $adr_fact = $this->t1db->query_obj(
                        "select * from venteadr where id=?",
                        array($commande->adrfact)
                    )
                ) {
                    throw new ImportException("Failed to find billing adresse ID=$commande->adrfact");
                }

                $con = Propel::getConnection(OrderTableMap::DATABASE_NAME);

                $con->beginTransaction();

                try {

                    $order = new Order();

                    $delivery_adr = new OrderAddress();

                    $delivery_adr
                        ->setCustomerTitleId($this->getT2CustomerTitle($adr_livr->raison)->getId())
                        ->setCompany(isset($adr_livr->entreprise) ? $adr_livr->entreprise : '')
                        ->setFirstname($adr_livr->prenom)
                        ->setLastname($adr_livr->nom)
                        ->setAddress1($adr_livr->adresse1)
                        ->setAddress2($adr_livr->adresse2)
                        ->setAddress3($adr_livr->adresse3)
                        ->setZipcode($adr_livr->cpostal)
                        ->setCity($adr_livr->ville)
                        ->setPhone($adr_livr->tel)
                        ->setCountryId($this->getT2Country($adr_livr->pays)->getId())
                        ->save($con);

                    $billing_adr = new OrderAddress();

                    $billing_adr
                        ->setCustomerTitleId($this->getT2CustomerTitle($adr_fact->raison)->getId())
                        ->setCompany(isset($adr_fact->entreprise) ? $adr_fact->entreprise : '')
                        ->setFirstname($adr_fact->prenom)
                        ->setLastname($adr_fact->nom)
                        ->setAddress1($adr_fact->adresse1)
                        ->setAddress2($adr_fact->adresse2)
                        ->setAddress3($adr_fact->adresse3)
                        ->setZipcode($adr_fact->cpostal)
                        ->setCity($adr_fact->ville)
                        ->setPhone($adr_fact->tel)
                        ->setCountryId($this->getT2Country($adr_fact->pays)->getId())
                        ->save($con);

                    // Find the first availables delivery and payment modules, that's the best we can do.
                    $deliveryModule = ModuleQuery::create()->findOneByType(BaseModule::DELIVERY_MODULE_TYPE);
                    $paymentModule  = ModuleQuery::create()->findOneByType(BaseModule::PAYMENT_MODULE_TYPE);


                    $order
                        ->setRef($commande->ref)
                        ->setCustomer($customer)
                        ->setInvoiceDate($commande->datefact)
                        ->setCurrency($this->getT2Currency($commande->devise))
                        ->setCurrencyRate($this->getT2Currency($commande->devise)->getRate())
                        ->setTransactionRef($commande->transaction)
                        ->setDeliveryRef($commande->livraison)
                        ->setInvoiceRef($commande->facture)
                        ->setPostage($commande->port)
                        ->setStatusId($status->getId())
                        ->setLang($this->getT2Lang($commande->lang))
                        ->setDeliveryOrderAddressId($delivery_adr->getId())
                        ->setInvoiceOrderAddressId($billing_adr->getId())
                        ->setDeliveryModuleId($deliveryModule->getId())
                        ->setPaymentModuleId($paymentModule->getId())
                        ->save($con);

                    // Update the order reference
                    $order
                        ->setRef($commande->ref)
                        ->setCreatedAt($commande->date)
                    ->save();


                    if ($commande->remise > 0) {

                        $coupon = new OrderCoupon();

                        $coupon
                            ->setOrder($order)
                            ->setCode('Not Available')
                            ->setType('UNKNOWN')
                            ->setAmount($commande->remise)
                            ->setTitle('Imported from Thelia 1')
                            ->setShortDescription('Imported from Thelia 1')
                            ->setDescription('Imported from Thelia 1')
                            ->setExpirationDate(time())
                            ->setIsCumulative(false)
                            ->setIsRemovingPostage(false)
                            ->setIsAvailableOnSpecialOffers(false)
                            ->setSerializedConditions(new ConditionCollection(array()))
                            ->save($con);
                    }

                    $vps = $this->t1db->query_list("select * from venteprod where commande=?", array($commande->id));

                    foreach ($vps as $vp) {

                        $parent = 0;

                        if (isset($vp->parent) && $vp->parent != 0) {
                            $parent = $this->product_corresp->getT2($vp->parent);
                        }

                        $orderProduct = new OrderProduct();

                        $orderProduct
                            ->setOrder($order)
                            ->setProductRef($vp->ref)
                            ->setProductSaleElementsRef($vp->ref)
                            ->setTitle($vp->titre)
                            ->setChapo($vp->chapo)
                            ->setDescription($vp->description)
                            ->setPostscriptum("") // Undefined in T1
                            ->setQuantity($vp->quantite)
                            ->setPrice($vp->prixu)
                            ->setPromoPrice($vp->prixu)
                            ->setWasNew(false) // Not Available in T1
                            ->setWasInPromo(false) // Not Available in T1
                            ->setWeight(0) // Not Available in T1
                            ->setEanCode("") // Not Available in T1
                            ->setTaxRuleTitle("")
                            ->setTaxRuleDescription("")
                            ->setParent($parent)
                            ->save($con);

                        $orderProductTax = new OrderProductTax();

                        $orderProductTax
                            ->setAmount($vp->prixu * $vp->quantite * $vp->tva / 100)
                            ->setPromoAmount(0)
                            ->setOrderProduct($orderProduct)
                            ->setTitle("TVA $vp->tva %")
                            ->setDescription("TVA $vp->tva %")
                            ->save($con);
                    }

                    $con->commit();
                } catch (\Exception $ex) {
                    $con->rollBack();

                    throw $ex;
                }
            } catch (\Exception $ex) {

                Tlog::getInstance()->addError("Failed to import order ref $commande->ref: ", $ex->getMessage());

                $errors++;
            }
        }

        return new ImportChunkResult($count, $errors);
    }
}