<?php
namespace ImportT1\Import;

use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Event\UpdatePositionEvent;
use Thelia\Log\Tlog;
use Thelia\Model\Feature;
use Thelia\Core\Event\Feature\FeatureCreateEvent;
use Thelia\Core\Event\Feature\FeatureUpdateEvent;
use Thelia\Model\FeatureQuery;
use Thelia\Core\Event\Feature\FeatureAvCreateEvent;
use Thelia\Core\Event\Feature\FeatureAvUpdateEvent;
use Thelia\Model\FeatureAvQuery;

class FeaturesImport extends BaseImport {

    private $feat_corresp;
    private $feat_av_corresp;

    public function getTotalCount() {
        return 0;
    }

    public function preImport() {

        FeatureQuery::create()->deleteAll();
        FeatureAvQuery::create()->deleteAll();

        // Create T1 <-> T2 IDs correspondance tables
        $this->feat_corresp = new CorrespondanceTable(CorrespondanceTable::FEATURES, $this->t1db);
        $this->feat_corresp->reset();

        $this->feat_av_corresp = new CorrespondanceTable(CorrespondanceTable::FEATURES_AV, $this->t1db);
        $this->feat_av_corresp->reset();
    }

    public function import($startRecord = 0) {

        $res = array();

        $res[] = $this->importFeatures();
        $res[] = $this->importFeaturesAv();

            $ret = new ImportChunkResult($res[0]->getCount(), 0);

        foreach($res as $item) {
            //$ret->setCount($ret->getCount() + $item->getCount());
            $ret->setErrors($ret->getErrors() + $item->getErrors());
        }

        return $ret;
    }

    public function importFeatures() {

        $count = $errors = 0;

        $hdl = $this->t1db->query("select * from caracteristique order by classement");

        while ($hdl && $caracteristique = $this->t1db->fetch_object($hdl)) {

            $descs = $this->t1db->query_list("select * from caracteristiquedesc where caracteristique = ? order by lang asc", array($caracteristique->id));

            $idx = 0;

            $event = new FeatureCreateEvent();

            foreach($descs as $desc) {

                try {
                    $lang = $this->getT2Lang($desc->lang);

                    if ($idx == 0) {
                        $event
                        ->setLocale($lang->getLocale())
                        ->setTitle($desc->titre)
                        ;

                        $this->dispatcher->dispatch(TheliaEvents::FEATURE_CREATE, $event);

                        // Updater position
                        $update_position_event = new UpdatePositionEvent(
                                $event->getFeature()->getId(),
                                UpdatePositionEvent::POSITION_ABSOLUTE,
                                $caracteristique->classement);

                        $this->dispatcher->dispatch(TheliaEvents::FEATURE_UPDATE_POSITION, $update_position_event);

                        Tlog::getInstance()->info("Created feature ".$event->getFeature()->getId()." from $desc->titre ($caracteristique->id)");

                        $this->feat_corresp->addEntry($caracteristique->id, $event->getFeature()->getId());
                    }

                    $update_event = new FeatureUpdateEvent($event->getFeature()->getId());

                    $update_event
                    ->setLocale($lang->getLocale())
                    ->setTitle($desc->titre)
                    ->setChapo($desc->chapo)
                    ->setDescription($desc->description)
                    ->setPostscriptum('') // Doesn't exists in T1
                    ;

                    $this->dispatcher->dispatch(TheliaEvents::FEATURE_UPDATE, $update_event);

                    $idx++;
                }
                catch (ImportException $ex) {
                    Tlog::getInstance()->addError("Failed to create Feature from $desc->titre ($caracteristique->id): ", $ex->getMessage());

                    $errors++;
                }
            }

            $count++;
        }

        return new ImportChunkResult($count, $errors);
    }

    public function importFeaturesAv() {

        $count = $errors = 0;

        $hdl = $this->t1db->query("select * from caracdisp");

        while ($hdl && $caracdisp = $this->t1db->fetch_object($hdl)) {

            $descs = $this->t1db->query_list("select * from caracdispdesc where caracdisp = ? order by lang asc", array($caracdisp->id));

            $idx = 0;

            $event = new FeatureAvCreateEvent();

            foreach($descs as $desc) {

                try  {
                    $lang = $this->getT2Lang($desc->lang);

                    $feature_id = $this->feat_corresp->getT2($caracdisp->caracteristique);

                    if ($idx == 0) {

                        $event
                        ->setFeatureId($feature_id)
                        ->setLocale($lang->getLocale())
                        ->setTitle($desc->titre)
                        ;

                        $this->dispatcher->dispatch(TheliaEvents::FEATURE_AV_CREATE, $event);

                        // Updater position
                        $update_position_event = new UpdatePositionEvent(
                                $event->getFeatureAv()->getId(),
                                UpdatePositionEvent::POSITION_ABSOLUTE,
                                $desc->classement);

                        $this->dispatcher->dispatch(TheliaEvents::FEATURE_AV_UPDATE_POSITION, $update_position_event);

                        $this->feat_av_corresp->addEntry($caracdisp->id, $event->getFeatureAv()->getId());
                    }

                    $update_event = new FeatureAvUpdateEvent($event->getFeatureAv()->getId());

                    $update_event
                    ->setFeatureId($feature_id)
                    ->setLocale($lang->getLocale())
                    ->setTitle($desc->titre)
                    ->setChapo('') // Undefined in T1
                    ->setDescription('') // Undefined in T1
                    ->setPostscriptum('') // Undefined in T1
                    ;

                    $this->dispatcher->dispatch(TheliaEvents::FEATURE_AV_UPDATE, $update_event);

                    $idx++;
                }
                catch (ImportException $ex) {
                    Tlog::getInstance()->addError("Failed to create Feature Av: ", $ex->getMessage());

                    $errors++;
                }

                $count++;
            }
        }

        return new ImportChunkResult($count, $errors);
    }
}