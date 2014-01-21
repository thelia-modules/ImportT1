<?php
namespace ImportT1\Import;

use Thelia\Core\Translation\Translator;
/**
 * Manage a temporary table to store T1 <-> T2 ID correspondance
 */
class CorrespondanceTable {

    protected $table_name;
    protected $db;

    public function __construct($table_name, $db) {

        $this->table_name = $table_name;
        $this->db         = $db;
    }

    public function reset() {
        $this->db->query("DROP TABLE IF EXISTS `$this->table_name`");

        $this->db->query("
                 CREATE TABLE `$this->table_name` (
                     `idt1` INT(11),
                     `idt2` INT(11),
                    INDEX idt1 (idt1)
             )");
    }

    public function getT2($idt1) {

        if ($idt1 == 0) return 0;

        $obj = $this->db->query_obj("select idt2 from `$this->table_name` where idt1 = ?", array($idt1));

        if ($obj === false || intval($obj->idt2) == 0) {
            throw new ImportException(
                Translator::getInstance()->trans("Failed to find a Thelia 2 category for T1 category '%id'", array("%id" => $idt1)));
        }

        return $obj->idt2;
    }

    public function addEntry($idt1, $idt2) {
        $this->db->query("INSERT INTO `$this->table_name` (idt1, idt2) values(?, ?)", array($idt1, $idt2));
    }
}