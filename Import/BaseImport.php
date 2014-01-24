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
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Translation\Translator;
use Thelia\Exception\UrlRewritingException;
use Thelia\Log\Tlog;
use Thelia\Model\Country;
use Thelia\Model\CountryQuery;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\CustomerQuery;
use Thelia\Model\CustomerTitle;
use Thelia\Model\CustomerTitleI18nQuery;
use Thelia\Model\Lang;
use Thelia\Model\LangQuery;
use Thelia\Model\RewritingUrlQuery;

class BaseImport
{

    const CHUNK_SIZE = 100;

    protected $dispatcher;
    protected $t1db;

    public function __construct(EventDispatcherInterface $dispatcher, Db $t1db)
    {

        $this->dispatcher = $dispatcher;

        $this->t1db = $t1db;

        $this->t1db->connect();
    }

    public function getChunkSize()
    {
        return self::CHUNK_SIZE;
    }

    public function preImport()
    {
    }

    public function import($startRecord = 0)
    {
        // Override this method, please.
    }

    public function postImport()
    {
    }

    private $currency_cache;

    public function getT2Currency($t1id = false)
    {

        if (!isset($this->currency_cache)) {

            if ($t1id !== false)
                $obj = $this->t1db->query_obj("select * from devise where id=?", array($t1id));
            else {
                $obj = $this->t1db->query_obj("select * from devise where defaut=1");
            }

            if ($obj == false) {
                throw new ImportException(
                    Translator::getInstance()->trans(
                        "Failed to find the Thelia 1 currency %cur"
                    ), array('%cur' => $t1id === false ? 'Default' : "ID=$t1id"));

            }

            $currency = CurrencyQuery::create()->findOneByCode(strtolower($obj->code));

            if ($currency === null) {
                throw new ImportException(
                    Translator::getInstance()->trans(
                        "Failed to find a Thelia 2 lang for T1 lang code '%code'",
                        array("%code" => $obj->code)
                    ));
            }

            $this->currency_cache = $currency;
        }

        return $this->currency_cache;
    }


    private $lang_cache = array();

    /**
     * @param $id_lang_thelia_1
     * @return Lang
     * @throws ImportException
     */
    public function getT2Lang($id_lang_thelia_1)
    {

        if (!isset($this->lang_cache[$id_lang_thelia_1])) {

            $obj = $this->t1db->query_obj("select * from lang where id=?", array($id_lang_thelia_1));

            if ($obj == false) {
                throw new ImportException(
                    Translator::getInstance()->trans(
                        "Failed to find a Thelia 1 lang for id '%id'",
                        array("%id" => $id_lang_thelia_1)
                    ));

            }

            $lang = LangQuery::create()->findOneByCode(strtolower($obj->code));

            if ($lang === null) {
                throw new ImportException(
                    Translator::getInstance()->trans(
                        "Failed to find a Thelia 2 lang for T1 lang code '%code'",
                        array("%code" => $obj->code)
                    ));
            }

            $this->lang_cache[$id_lang_thelia_1] = $lang;
        }

        return $this->lang_cache[$id_lang_thelia_1];
    }

    private $title_cache = array();

    /**
     * @param $id_title_thelia_1
     * @return CustomerTitle
     * @throws ImportException
     */
    public function getT2CustomerTitle($id_title_thelia_1)
    {

        if (!isset($this->title_cache[$id_title_thelia_1])) {

            $obj = $this->t1db->query_obj("select * from raisondesc where raison=? limit 1", array($id_title_thelia_1));

            if ($obj == false) {
                throw new ImportException(
                    Translator::getInstance()->trans(
                        "Failed to find a Thelia 1 customer title for id '%id'",
                        array("%id" => $id_title_thelia_1)
                    ));

            }

            // Find the T1 objet lang
            $lang = $this->getT2Lang($obj->lang);

            // Get the T2 title for this lang
            $title = CustomerTitleI18nQuery::create()->filterByLocale($lang->getLocale())->findOneByShort($obj->court);

            if ($title === null) {
                throw new ImportException(
                    Translator::getInstance()->trans(
                        "Failed to find a Thelia 2 title for T1 title '%id', %short",
                        array("%id" => $obj->id, "%short" => $obj->short)
                    ));
            }

            $this->title_cache[$id_title_thelia_1] = $title;
        }

        return $this->title_cache[$id_title_thelia_1];
    }


    private $country_cache = array();

    /**
     * @param $id_country_thelia_1
     * @return Country
     * @throws ImportException
     */
    public function getT2Country($id_country_thelia_1)
    {

        if (!isset($this->title_cache[$id_country_thelia_1])) {

            $obj = $this->t1db->query_obj("select isoalpha3 from pays where id=?", array($id_country_thelia_1));

            if ($obj == false) {
                throw new ImportException(
                    Translator::getInstance()->trans(
                        "Failed to find a Thelia 1 country for id '%id'",
                        array("%id" => $id_country_thelia_1)
                    ));

            }
            // Get the T2 country
            $country = CountryQuery::create()->findOneByIsoalpha3($obj->isoalpha3);

            if ($country === null) {
                throw new ImportException(
                    Translator::getInstance()->trans(
                        "Failed to find a Thelia 2 country for T1 country '%id'",
                        array("%id" => $obj->id)
                    ));
            }

            $this->title_cache[$id_country_thelia_1] = $country;
        }

        return $this->title_cache[$id_country_thelia_1];
    }


    public function getT2Customer($id_client_thelia_1)
    {

        $t1obj = $this->t1db->query_obj("select id, email from client where id=?", array($id_client_thelia_1));

        if ($t1obj == false) {
            throw new ImportException(
                Translator::getInstance()->trans(
                    "Failed to find a Thelia 1 customer for id '%id'",
                    array("%id" => $id_client_thelia_1)
                ));

        }

        // Get the T2 customer
        $t2obj = CustomerQuery::create()->findOneByEmail($t1obj->email);

        if ($t2obj === null) {
            throw new ImportException(
                Translator::getInstance()->trans(
                    "Failed to find a Thelia 2 customer for T1 customer '%id'",
                    array("%id" => $t1obj->id)
                ));
        }

        return $t2obj;
    }

    protected function updateRewrittenUrl($t2_object, $locale, $id_lang_t1, $fond_t1, $params_t1)
    {

        $t1_obj = $this->t1db->query_obj(
            "select * from reecriture where fond=? and param=? and lang=? and actif=1",
            array($fond_t1, "&$params_t1", $id_lang_t1)
        );

        if ($t1_obj) {
            try {
                // Delete all previous instance for the T2 object and for the rewtitten URL
                RewritingUrlQuery::create()
                    ->filterByViewLocale($locale)
                    ->findByViewId($t2_object->getId())
                    ->delete();

                RewritingUrlQuery::create()
                    ->filterByViewLocale($locale)
                    ->findByUrl($t1_obj->url)
                    ->delete();

                $t2_object->setRewrittenUrl($locale, $t1_obj->url);
            } catch (UrlRewritingException $ex) {
                Tlog::getInstance()
                    ->addError(
                        "Failed to create rewritten URL for locale $locale, fond $fond_t1, with params $params_t1: ",
                        $ex->getMessage()
                    );
            }
        }
    }
}