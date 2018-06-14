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

use ImportT1\ImportT1;
use ImportT1\Model\Db;
use Propel\Runtime\Propel;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Translation\Translator;
use Thelia\Log\Tlog;
use Thelia\Model\Country;
use Thelia\Model\CountryI18nQuery;
use Thelia\Model\CountryQuery;
use Thelia\Model\Currency;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\CustomerTitle;
use Thelia\Model\CustomerTitleI18nQuery;
use Thelia\Model\Lang;
use Thelia\Model\LangQuery;
use Thelia\Model\Map\ProductTableMap;
use Thelia\Model\Map\RewritingUrlTableMap;
use Thelia\Model\RewritingUrlQuery;

abstract class BaseImport
{
    const CHUNK_SIZE = 100;

    protected $dispatcher;
    protected $t1db;
    protected $thelia_version;

    public function __construct(EventDispatcherInterface $dispatcher, Db $t1db)
    {
        $this->dispatcher = $dispatcher;

        $this->t1db = $t1db;

        $this->t1db->connect();

        $hdl = $this->t1db->query("select valeur from variable where nom = 'version'");

        $version = $this->t1db->fetch_column($hdl);

        $this->thelia_version = intval(substr($version, 0, 3));

        $serviceContainer = Propel::getServiceContainer();

        $serviceContainer->setLogger('defaultLogger', Tlog::getInstance());
        $con = Propel::getConnection(ProductTableMap::DATABASE_NAME);
        $con->useDebug(true);
    }

    public function getChunkSize()
    {
        return self::CHUNK_SIZE;
    }

    public function preImport()
    {
    }

    /**
     * @param int $startRecord
     * @return ImportChunkResult
     */
    abstract public function import($startRecord = 0);

    /**
     * @return int
     */
    abstract public function getTotalCount();

    public function postImport()
    {
    }

    private $currency_cache;

    /**
     * @param  bool $t1id
     * @return \Thelia\Model\Currency
     * @throws ImportException
     */
    public function getT2Currency($t1id = false)
    {
        if (!isset($this->currency_cache)) {
            $code = false;

            if ($t1id !== false && $t1id > 0) {
                $code = $this->t1db->query_obj("select * from devise where id=?", array($t1id))->code;
            } else {
                try {
                    $code = $this->t1db->query_obj("select * from devise where defaut=1")->code;
                } catch (\Exception $ex) {
                    // Thelia 1.5.1, no default column, use EUR
                    $code = "EUR";
                }
            }

            if ($code === false) {
                throw new ImportException(
                    Translator::getInstance()->trans(
                        "Failed to find the Thelia 1 currency %cur",
                        array('%cur' => $t1id === false ? 'Default' : "ID=$t1id"),
                        ImportT1::DOMAIN
                    )
                );

            }

            $currency = CurrencyQuery::create()->findOneByCode(strtolower($code));

            if ($currency === null) {
                // Use default currency
                $currency = Currency::getDefaultCurrency();

                Tlog::getInstance()->addWarning("Using default currency for Thelia 1 currency '$code'.");

                throw new ImportException(
                    Translator::getInstance()->trans(
                        "Failed to find a Thelia 2 currency for Thelia 1 currency code '%code'",
                        array("%code" => $code),
                        ImportT1::DOMAIN
                    )
                );
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
        $lang = null;

        if (!isset($this->lang_cache[$id_lang_thelia_1])) {

            if ($id_lang_thelia_1 > 0) {
                $obj = $this->t1db->query_obj("select * from lang where id=?", array($id_lang_thelia_1));
            } else {
                $obj = $this->t1db->query_obj("select * from lang order by id asc limit 1");
            }

            if ($obj == false || $obj == null) {
                throw new ImportException(
                    Translator::getInstance()->trans(
                        "Failed to find a Thelia 1 lang for id '%id'",
                        array("%id" => $id_lang_thelia_1),
                        ImportT1::DOMAIN
                    )
                );
            }

            if (isset($obj->code)) {
                $lang = LangQuery::create()->findOneByCode(strtolower($obj->code));
            } else // Thelia < 1.5.1
            {
                $lang = LangQuery::create()->filterByLocale("fr_FR")->findOneByTitle($obj->description);
            }

            if ($lang === null) {

                // If the lang id is not zero, create it in T2
                if ($id_lang_thelia_1 > 0) {

                    $lang = new Lang();

                    if (isset($obj->code)) {
                        $lang
                            ->setTitle($obj->description)
                            ->setCode($obj->code)
                            ->setLocale("$obj->code" . "_" . strtoupper($obj->code));
                    } else {
                        $lang
                            ->setTitle("Imported Thelia lang $id_lang_thelia_1")
                            ->setCode("")
                            ->setLocale("");
                    }

                    $lang
                        ->setDatetimeFormat('d/m/Y H:i:s')
                        ->setDecimals(2)
                        ->setDecimalSeparator('.')
                        ->setThousandsSeparator(' ')
                        ->setTimeFormat('H:i:s')
                        ->setDateFormat('d/m/Y')
                        ->save();

                    Tlog::getInstance()->addInfo("Created Thelia 2 lang from Thelia 1 lang ID=$id_lang_thelia_1");

                } else {
                    // Use default lang
                    $lang = Lang::getDefaultLanguage();

                    Tlog::getInstance()->addWarning("Using default currency for Thelia 1 currency '$obj->code'.");

                    throw new ImportException(
                        Translator::getInstance()->trans(
                            "Failed to find a Thelia 2 lang for Thelia 1 lang id %id",
                            array("%id" => $id_lang_thelia_1),
                            ImportT1::DOMAIN
                        )
                    );
                }
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
        $title = null;

        if (!isset($this->title_cache[$id_title_thelia_1])) {
            try {
                $obj = $this->t1db->query_obj(
                    "select * from raisondesc where raison=? limit 1",
                    array($id_title_thelia_1)
                );

                if ($obj == false) {
                    throw new ImportException(
                        Translator::getInstance()->trans(
                            "Failed to find a Thelia 1 customer title for id '%id'",
                            array("%id" => $id_title_thelia_1),
                            ImportT1::DOMAIN
                        )
                    );
                }

                // Find the T1 object lang
                $lang = $this->getT2Lang($obj->lang);

                // Get the T2 title for this lang
                $title = CustomerTitleI18nQuery::create()->filterByLocale($lang->getLocale())->findOneByShort(
                    $obj->court
                );

                if ($title === null) {
                    throw new ImportException(
                        Translator::getInstance()->trans(
                            "Failed to find a Thelia 2 customer title for Thelia 1 short title '%title'",
                            array("%title" => $obj->court),
                            ImportT1::DOMAIN
                        )
                    );
                }

            } catch (\Exception $ex) {
                if ($id_title_thelia_1 == 1 || $id_title_thelia_1 > 3) {
                    $title = CustomerTitleI18nQuery::create()->filterByLocale('fr_FR')->findOneByShort('Mme');
                } else {
                    if ($id_title_thelia_1 == 2) {
                        $title = CustomerTitleI18nQuery::create()->filterByLocale('fr_FR')->findOneByShort('Mlle');
                    } else {
                        $title = CustomerTitleI18nQuery::create()->filterByLocale('fr_FR')->findOneByShort('M.');
                    }
                }
            }

            if ($title === null) {
                throw new ImportException(
                    Translator::getInstance()->trans(
                        "Failed to find a Thelia 2 title for Thelia 1 title ID '%id'",
                        array("%id" => $id_title_thelia_1),
                        ImportT1::DOMAIN
                    )
                );
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
        if (!isset($this->country_cache[$id_country_thelia_1])) {

            $id = $id_country_thelia_1;

            $country = null;

            try {
                $obj = $this->t1db->query_obj("select isoalpha3 from pays where id=?", array($id_country_thelia_1));
            } catch (\Exception $ex) {
                $obj = false;
            }

            if ($obj == false) {

                $obj = $this->t1db->query_obj(
                    "select pays, titre from paysdesc where pays=? and lang=1",
                    array($id_country_thelia_1)
                );

                if ($obj == false) {
                    throw new ImportException(
                        Translator::getInstance()->trans(
                            "Failed to find a Thelia 1 country for id '%id'",
                            array("%id" => $id_country_thelia_1),
                            ImportT1::DOMAIN
                        )
                    );
                }

                $id = $obj->pays;

                if (null === $countryI18n = CountryI18nQuery::create()->filterByLocale('fr_FR')->findOneByTitle("$obj->titre%")) {
                    // Essayer autre chose si le pays est de la forme "USA - New York"
                    $splitted = explode(' - ', $obj->titre);
                    if (count($splitted) == 2) {
                        if (null === $countryI18n = CountryI18nQuery::create()->filterByLocale('fr_FR')->findOneByTitle($splitted[0])) {
                            throw new ImportException(
                                Translator::getInstance()->trans(
                                    "Failed to find a Thelia 2 country for Thelia 1 country '%title'",
                                    array("%title" => $splitted[0] . " (exracted from $obj->titre)"),
                                    ImportT1::DOMAIN
                                )
                            );
                        }
                    } else {
                        throw new ImportException(
                            Translator::getInstance()->trans(
                                "Failed to find a Thelia 2 country for Thelia 1 country '%title'",
                                array("%title" => $obj->titre),
                                ImportT1::DOMAIN
                            )
                        );
                    }
                }

                $country = CountryQuery::create()->findPk($countryI18n->getId());
            } else {
                // Get the T2 country
                $country = CountryQuery::create()->findOneByIsoalpha3($obj->isoalpha3);
            }

            if ($country == null) {
                throw new ImportException(
                    Translator::getInstance()->trans(
                        "Failed to find a Thelia 2 country for Thelia 1 country '%id'",
                        array("%id" => $id),
                        ImportT1::DOMAIN
                    )
                );
            }

            $this->country_cache[$id_country_thelia_1] = $country;
        }

        return $this->country_cache[$id_country_thelia_1];
    }

    function mb_strtr($str, $from, $to)
    {
        return str_replace($this->mb_str_split($from), $this->mb_str_split($to), $str);
    }

    function mb_str_split($str) {
        return preg_split('~~u', $str, null, PREG_SPLIT_NO_EMPTY);

    }

    function ereg_caracspec($chaine)
    {
        $avant = "àáâãäåòóôõöøèéêëçìíîïùúûüÿñÁÂÀÅÃÄÇÉÊÈËÓÔÒØÕÖÚÛÙÜ:;,°";
        $apres = "aaaaaaooooooeeeeciiiiuuuuynaaaaaaceeeeoooooouuuu----";

        $chaine = strtolower($chaine);
        $chaine = $this->mb_strtr($chaine, $avant, $apres);

        $chaine = str_replace("(", "", $chaine);
        $chaine = str_replace(")", "", $chaine);
        $chaine = str_replace("/", "-", $chaine);
        $chaine = str_replace(" ", "-", $chaine);
        $chaine = str_replace(chr(39), "-", $chaine);
        $chaine = str_replace(chr(234), "e", $chaine);
        $chaine = str_replace("'", "-", $chaine);
        $chaine = str_replace("&", "-", $chaine);
        $chaine = str_replace("?", "", $chaine);
        $chaine = str_replace("*", "-", $chaine);
        $chaine = str_replace(".", "", $chaine);
        $chaine = str_replace("!", "", $chaine);
        $chaine = str_replace("+", "-", $chaine);
        $chaine = preg_replace('/-+/', '-', $chaine);
        $chaine = str_replace("%", "", $chaine);

        return $chaine;
    }

    function eregurl($url)
    {
        $url =  html_entity_decode($url);

        $url = $this->ereg_caracspec($url);

        $url = strip_tags($url);

        return $url . ".html";
    }

    protected function makeT1RewrittenUrl($t1Object, $t1descObj, $id_lang_t1) {
        return false;
    }

    protected function updateRewrittenUrl($t2_object, $locale, $id_lang_t1, $fond_t1, $params_t1, $t1_object, $t1_desc_object)
    {
        $url = null;

        try {
            $t1_obj = $this->t1db->query_obj(
                "select * from reecriture where fond=? and param like? and lang=? and actif=1",
                array($fond_t1, "&$params_t1", $id_lang_t1)
            );

            $url = $t1_obj->url;
        }
        catch (\Exception $ex) {
            $url = $this->makeT1RewrittenUrl($t1_object, $t1_desc_object, $id_lang_t1);
        }

        if (null !== $url) {
            Tlog::getInstance()->info("Found rewritten URL $url for fond $fond_t1, with params $params_t1");

            try {
                // Delete all previous instance for the T2 object and for the rewritten URL
                // Also empty url rewriting table
                $con = Propel::getConnection(RewritingUrlTableMap::DATABASE_NAME);

                $con->exec('SET FOREIGN_KEY_CHECKS=0');

                RewritingUrlQuery::create()
                    ->filterByViewLocale($locale)
                    ->filterByView($t2_object->getRewrittenUrlViewName())
                    ->findByViewId($t2_object->getId())
                    ->delete();

                RewritingUrlQuery::create()
                    ->filterByViewLocale($locale)
                    ->findByUrl($url)
                    ->delete();

                $con->exec('SET FOREIGN_KEY_CHECKS=1');

                $t2_object->setRewrittenUrl($locale, $url);

                Tlog::getInstance()->info("Imported rewritten URL for locale $locale, $url");

            } catch (\Exception $ex) {
                Tlog::getInstance()
                    ->addError(
                        "Failed to create rewritten URL for locale $locale, fond $fond_t1, with params $params_t1: ",
                        $ex->getMessage()
                    );
            }
        } else {
            Tlog::getInstance()
                ->addNotice(
                    "No rewritten URL was found for locale $locale, fond '$fond_t1', with params '$params_t1', lang $id_lang_t1"
                );
        }
    }
}
