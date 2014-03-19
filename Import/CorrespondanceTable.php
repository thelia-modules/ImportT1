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

use Thelia\Core\Translation\Translator;

/**
 * Manage a temporary table to store T1 <-> T2 ID correspondance
 */
class CorrespondanceTable
{

    const CUSTOMERS = 't1_t2_customer';
    const ATTRIBUTES = 't1_t2_attribute';
    const ATTRIBUTES_AV = 't1_t2_attributes_av';
    const CATEGORIES = 't1_t2_category';
    const CONTENTS = 't1_t2_content';
    const FEATURES = 't1_t2_feature';
    const FEATURES_AV = 't1_t2_feature_av';
    const FOLDERS = 't1_t2_folder';
    const PRODUCTS = 't1_t2_product';
    const TEMPLATES = 't1_t2_template';
    const TAX = 't1_t2_tax';
    const ORDERS = 't1_t2_order';

    protected $table_name;
    protected $db;

    public function __construct($table_name, $db)
    {

        $this->table_name = $table_name;
        $this->db = $db;
    }

    public function reset()
    {
        $this->db->query("DROP TABLE IF EXISTS `$this->table_name`");

        $this->db->query(
            "
                 CREATE TABLE `$this->table_name` (
                     `idt1` INT(11),
                     `idt2` INT(11),
                    INDEX idt1 (idt1)
             )"
        );
    }

    public function getT2($idt1, $failIfZero = true)
    {
        $obj = $this->db->query_obj("select idt2 from `$this->table_name` where idt1 = ?", array($idt1));

        if ($obj === false || intval($obj->idt2) == 0) {

            if (! $failIfZero) return 0;

            $obj_name = ucfirst(preg_replace("/t1_t2_/", "", $this->table_name));

            throw new ImportException(
                Translator::getInstance()->trans(
                    "Failed to find a Thelia 2 %obj for Thelia 1 ID '%id'",
                    array("%obj" => $obj_name, "%id" => $idt1, "%table" => $this->table_name)
                ));
        }

        return $obj->idt2;
    }

    public function addEntry($idt1, $idt2)
    {
        $this->db->query("INSERT INTO `$this->table_name` (idt1, idt2) values(?, ?)", array($idt1, $idt2));
    }
}
