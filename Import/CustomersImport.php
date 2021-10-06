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

use ImportT1\Model\CustomerTemp;
use ImportT1\Model\Db;
use Propel\Runtime\Propel;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\Event\Address\AddressCreateOrUpdateEvent;
use Thelia\Core\Event\Customer\CustomerCreateOrUpdateEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Log\Tlog;
use Thelia\Model\AddressQuery;
use Thelia\Model\Base\OrderAddressQuery;
use Thelia\Model\CustomerQuery;
use Thelia\Model\CustomerTitle;
use Thelia\Model\CustomerTitleQuery;
use Thelia\Model\Map\CustomerTitleTableMap;
use Thelia\Model\OrderQuery;

class CustomersImport extends BaseImport
{

    protected $cust_corresp;

    public function __construct(EventDispatcherInterface $dispatcher, Db $t1db, RequestStack $requestStack)
    {

        parent::__construct($dispatcher, $t1db, $requestStack->getCurrentRequest()->getSession());

        $this->cust_corresp = new CorrespondanceTable(CorrespondanceTable::CUSTOMERS, $this->t1db);
    }

    public function getTotalCount()
    {
        return $this->t1db->num_rows(
            $this->t1db->query("select id from client")
        );
    }

    /**
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function preImport()
    {
        // Empty address, customer and customer title table
        OrderQuery::create()->deleteAll();
        AddressQuery::create()->deleteAll();
        OrderAddressQuery::create()->deleteAll();
        CustomerQuery::create()->deleteAll();

        $this->cust_corresp->reset();

        if ($this->thelia_version > 150) {
            $this->importCustomerTitle();
        }
    }

    public function import($startRecord = 0)
    {

        $count = 0;

        $errors = 0;

        $hdl = $this->t1db->query(
            sprintf("select * from client order by id asc limit %d, %d", intval($startRecord), $this->getChunkSize())
        );

        while ($hdl && $client = $this->t1db->fetch_object($hdl)) {
            $count++;

            try {
                $this->cust_corresp->getT2($client->id);

                Tlog::getInstance()->warning("Customer ID=$client->id ref=$client->ref already imported.");

                continue;
            } catch (ImportException $ex) {
                // Okay, the order was not imported.
            }

            try {
                $title = $this->getT2CustomerTitle($client->raison);
                $lang = $this->getT2Lang($client->lang);
                $country = $this->getT2Country($client->pays);

                try {
                    $sponsor = $this->cust_corresp->getT2($client->parrain);
                } catch (ImportException $ex) {
                    $sponsor = '';
                }

                $event = new CustomerCreateOrUpdateEvent(
                    $title->getId(),
                    $client->prenom,
                    $client->nom,
                    $client->adresse1,
                    $client->adresse2,
                    $client->adresse3,
                    $client->telfixe,
                    $client->telport,
                    $client->cpostal,
                    $client->ville,
                    $country->getId(),
                    $client->email,
                    $client->email, // Password !
                    $lang->getId(),
                    $client->type == 1 ? true : false, // Revendeur
                    (int)$sponsor,
                    $client->pourcentage,
                    $client->entreprise,
                    $client->ref
                );

                $this->dispatcher->dispatch($event, TheliaEvents::CUSTOMER_CREATEACCOUNT);

                Tlog::getInstance()->info(
                    "Created customer " . $event->getCustomer()->getId() . " from $client->ref ($client->id)"
                );

                $customerTemp = new CustomerTemp();
                $customerTemp
                    ->setEmail($client->email)
                    ->setPassword($client->motdepasse)
                    ->save()
                ;

                // Import customer addresses
                $a_hdl = $this->t1db->query("select * from adresse where client=?", array($client->id));

                while ($a_hdl && $adresse = $this->t1db->fetch_object($a_hdl)) {
                    try {
                        $title = $this->getT2CustomerTitle($adresse->raison);
                        $country = $this->getT2Country($adresse->pays);

                        $adr_event = new AddressCreateOrUpdateEvent(
                            $adresse->libelle,
                            $title->getId(),
                            $adresse->prenom,
                            $adresse->nom,
                            $adresse->adresse1,
                            $adresse->adresse2,
                            $adresse->adresse3,
                            $adresse->cpostal,
                            $adresse->ville,
                            $country->getId(),
                            '',
                            $adresse->tel,
                            isset($adresse->entreprise) ? $adresse->entreprise : ''
                        );

                        $adr_event->setCustomer($event->getCustomer());

                        $this->dispatcher->dispatch($adr_event, TheliaEvents::ADDRESS_CREATE);

                        Tlog::getInstance()->info(
                            "Created address " . $adr_event->getAddress()->getId(
                            ) . " for customer $client->ref ($client->id)"
                        );

                    } catch (ImportException $ex) {
                        Tlog::getInstance()->addError(
                            "Failed to create address ID $adresse->id for customer ref $client->ref:",
                            $ex->getMessage()
                        );
                    }
                }

                $this->cust_corresp->addEntry($client->id, $event->getCustomer()->getId());
            } catch (\Exception $ex) {
                Tlog::getInstance()->addError("Failed to import customer ref $client->ref :", $ex->getMessage());

                $errors++;
            }
        }

        return new ImportChunkResult($count, $errors);
    }

    public function importCustomerTitle()
    {
        $con = Propel::getConnection(CustomerTitleTableMap::DATABASE_NAME);

        $con->beginTransaction();

        try {
            CustomerTitleQuery::create()->deleteAll();

            $hdl = $this->t1db->query("select * from raison order by classement");

            while ($hdl && $raison = $this->t1db->fetch_object($hdl)) {
                $ct = new CustomerTitle();

                $descs = $this->t1db->query_list(
                    "select * from raisondesc where raison = ? order by lang asc",
                    array($raison->id)
                );

                foreach ($descs as $desc) {
                    $lang = $this->getT2Lang($desc->lang);

                    $ct
                        ->setLocale($lang->getLocale())
                        ->setByDefault($raison->defaut ? true : false)
                        ->setPosition($raison->classement)
                        ->setLong($desc->long)
                        ->setShort($desc->court)
                        ->save();
                }
            }

            $con->commit();

        } catch (\Exception $ex) {
            $con->rollBack();
            Tlog::getInstance()->error(
                "Failed to import Thelia 1 customer titles (not a problem for Thelia 1.4.x). Cause: " . $ex->getMessage()
            );
        }
    }
}
