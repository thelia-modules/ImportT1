<?php
namespace ImportT1\Import;

use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Event\UpdatePositionEvent;
use Thelia\Log\Tlog;
use Thelia\Model\AttributeQuery;
use Thelia\Core\Event\Attribute\AttributeCreateEvent;
use Thelia\Core\Event\Attribute\AttributeUpdateEvent;
use Thelia\Core\Event\Attribute\AttributeAvCreateEvent;
use Thelia\Core\Event\Attribute\AttributeAvUpdateEvent;
use Thelia\Model\AttributeAvQuery;

class AttributesImport extends BaseImport {

    private $attr_corresp;
    private $attr_av_corresp;

    public function getTotalCount() {
        return 0;
    }

    public function preImport() {

        AttributeQuery::create()->deleteAll();
        AttributeAvQuery::create()->deleteAll();

        // Create T1 <-> T2 IDs correspondance tables
        $this->attr_corresp = new CorrespondanceTable('t1_t2_attributes', $this->t1db);
        $this->attr_corresp->reset();

        $this->attr_av_corresp = new CorrespondanceTable('t1_t2_attributes_av', $this->t1db);
        $this->attr_av_corresp->reset();
    }

    public function import($startRecord = 0) {

        $res = array();

        $res[] = $this->importAttributes();
        $res[] = $this->importAttributesAv();

        $ret = new ImportChunkResult($res[0]->getCount(), 0);

        foreach($res as $item) {
            //$ret->setCount($ret->getCount() + $item->getCount());
            $ret->setErrors($ret->getErrors() + $item->getErrors());
        }

        return $ret;
    }

    public function importAttributes() {

        $count = $errors = 0;

        $hdl = $this->t1db->query("select * from declinaison order by classement");

        while ($hdl && $declinaison = $this->t1db->fetch_object($hdl)) {

            $descs = $this->t1db->query_list("select * from declinaisondesc where declinaison = ? order by lang asc", array($declinaison->id));

            $idx = 0;

            $event = new AttributeCreateEvent();

            foreach($descs as $desc) {

                try {
                    $lang = $this->getT2Lang($desc->lang);

                    if ($idx == 0) {
                        $event
                        ->setLocale($lang->getLocale())
                        ->setTitle($desc->titre)
                        ;

                        $this->dispatcher->dispatch(TheliaEvents::ATTRIBUTE_CREATE, $event);

                        // Updater position
                        $update_position_event = new UpdatePositionEvent(
                                $event->getAttribute()->getId(),
                                UpdatePositionEvent::POSITION_ABSOLUTE,
                                $declinaison->classement);

                        $this->dispatcher->dispatch(TheliaEvents::ATTRIBUTE_UPDATE_POSITION, $update_position_event);

                        Tlog::getInstance()->info("Created attribute ".$event->getAttribute()->getId()." from $desc->titre ($declinaison->id)");

                        $this->attr_corresp->addEntry($declinaison->id, $event->getAttribute()->getId());
                    }

                    $update_event = new AttributeUpdateEvent($event->getAttribute()->getId());

                    $update_event
                    ->setLocale($lang->getLocale())
                    ->setTitle($desc->titre)
                    ->setChapo($desc->chapo)
                    ->setDescription($desc->description)
                    ->setPostscriptum('') // Doesn't exists in T1
                    ;

                    $this->dispatcher->dispatch(TheliaEvents::ATTRIBUTE_UPDATE, $update_event);

                    $idx++;
                }
                catch (ImportException $ex) {
                    Tlog::getInstance()->addError("Failed to create Attribute from $desc->titre ($declinaison->id): ", $ex->getMessage());

                    $errors++;
                }

                $count++;
            }
        }

        return new ImportChunkResult($count, $errors);
    }

    public function importAttributesAv() {

        $count = $errors = 0;

        $hdl = $this->t1db->query("select * from declidisp");

        while ($hdl && $declidisp = $this->t1db->fetch_object($hdl)) {

            $descs = $this->t1db->query_list("select * from declidispdesc where declidisp = ? order by lang asc", array($declidisp->id));

            $idx = 0;

            $event = new AttributeAvCreateEvent();

            foreach($descs as $desc) {

                try {
                    $lang = $this->getT2Lang($desc->lang);

                    $attribute_id = $this->attr_corresp->getT2($declidisp->declinaison);

                    if ($idx == 0) {

                        $event
                        ->setAttributeId($attribute_id)
                        ->setLocale($lang->getLocale())
                        ->setTitle($desc->titre)
                        ;

                        $this->dispatcher->dispatch(TheliaEvents::ATTRIBUTE_AV_CREATE, $event);

                        // Updater position
                        $update_position_event = new UpdatePositionEvent(
                                $event->getAttributeAv()->getId(),
                                UpdatePositionEvent::POSITION_ABSOLUTE,
                                $desc->classement);

                        $this->dispatcher->dispatch(TheliaEvents::ATTRIBUTE_AV_UPDATE_POSITION, $update_position_event);

                        $this->attr_av_corresp->addEntry($declidisp->id, $event->getAttributeAv()->getId());
                    }

                    $update_event = new AttributeAvUpdateEvent($event->getAttributeAv()->getId());

                    $update_event
                    ->setAttributeId($attribute_id)
                    ->setLocale($lang->getLocale())
                    ->setTitle($desc->titre)
                    ->setChapo('') // Undefined in T1
                    ->setDescription('') // Undefined in T1
                    ->setPostscriptum('') // Undefined in T1
                    ;

                    $this->dispatcher->dispatch(TheliaEvents::ATTRIBUTE_AV_UPDATE, $update_event);

                    $idx++;
                }
                catch (ImportException $ex) {
                    Tlog::getInstance()->addError("Failed to create Attribute Av: ", $ex->getMessage());

                    $errors++;
                }

                $count++;
            }
        }

        return new ImportChunkResult($count, $errors);
    }
}