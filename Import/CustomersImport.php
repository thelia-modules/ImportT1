<?php
namespace ImportT1\Import;

use Thelia\Model\AddressQuery;
use Thelia\Model\CustomerQuery;
use Thelia\Model\CustomerTitleQuery;
use Thelia\Model\CustomerTitle;
use Thelia\Core\Event\Customer\CustomerCreateOrUpdateEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Event\Address\AddressCreateOrUpdateEvent;
use Thelia\Log\Tlog;

class CustomersImport extends BaseImport {

    public function getTotalCount() {
        return $this->t1db->num_rows(
                $this->t1db->query("select id from client")
        );
    }

    public function preImport() {
        // Empty address, customer and customer title table
        AddressQuery::create()->deleteAll();
        CustomerQuery::create()->deleteAll();
        CustomerTitleQuery::create()->deleteAll();

        $this->importCustomerTitle();
    }

    public function import($startRecord = 0) {

        $count = 0;

        $errors = 0;

        $hdl = $this->t1db->query(sprintf("select * from client order by id asc limit %d, %d", intval($startRecord), $this->getChunkSize()));

        while ($hdl && $client = $this->t1db->fetch_object($hdl)) {

            try {
                $title   = $this->getT2CustomerTitle($client->raison);
                $lang    = $this->getT2Lang($client->lang);
                $country = $this->getT2Country($client->pays);

                try {
                    $sponsor = $this->getT2Customer($client->parrain)->getRef();
                }
                catch (ImportException $ex) {
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
                        $sponsor,
                        $client->pourcentage,
                        $client->entreprise,
                        $client->ref
                );

                $this->dispatcher->dispatch(TheliaEvents::CUSTOMER_CREATEACCOUNT, $event);

                Tlog::getInstance()->info("Created customer ".$event->getCustomer()->getId()." from $client->ref ($client->id)");

                // Import customer addresses
                $a_hdl = $this->t1db->query("select * from adresse where client=?", array($client->id));

                while ($a_hdl && $adresse = $this->t1db->fetch_object($a_hdl)) {

                    try {
                        $title   = $this->getT2CustomerTitle($adresse->raison);
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
                            $adresse->entreprise
                        );

                        $adr_event->setCustomer($event->getCustomer());

                        $this->dispatcher->dispatch(TheliaEvents::ADDRESS_CREATE, $adr_event);

                        Tlog::getInstance()->info("Created address ".$adr_event->getAddress()->getId()." for customer $client->ref ($client->id)");

                    }
                    catch (ImportException $ex) {
                        Tlog::getInstance()->addError("Failed to create address ID $adresse->id for customer ref $client->ref:", $ex->getMessage());
                    }
                }
            }
            catch (ImportException $ex) {

                Tlog::getInstance()->addError("Failed to create customer ref $client->ref :", $ex->getMessage());

                $errors++;
            }

            $count++;
        }

        return new ImportChunkResult($count, $errors);
    }

    public function importCustomerTitle() {

        $hdl = $this->t1db->query("select * from raison order by classement");

        while ($hdl && $raison = $this->t1db->fetch_object($hdl)) {

            $ct = new CustomerTitle();

            $descs = $this->t1db->query_list("select * from raisondesc where raison = ? order by lang asc", array($raison->id));

            foreach($descs as $desc) {

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
    }

}