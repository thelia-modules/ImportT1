<?php
namespace ImportT1\Model;

use Thelia\Core\HttpFoundation\Request;

class Db
{
    protected $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function connect() {

        $dbinfo = $this->getDbInfo();

        $MYSQL_ATTR_INIT_COMMAND = 1002;

        $this->pdo = new \PDO(
                'mysql:host='.$dbinfo->getHostname().';dbname='.$dbinfo->getDbname(),
                $dbinfo->getUsername(),
                $dbinfo->getPassword(),
                array($MYSQL_ATTR_INIT_COMMAND => "SET NAMES 'UTF8'")
        );
    }

    public function getDbInfo() {
        return $this->request->getSession()->get('importt1-database-info', new DatabaseInfo());
    }

    public function setDbInfo($dbinfo) {
        $this->request->getSession()->set('importt1-database-info', $dbinfo);

        return $this;
    }

    public function hasClientPath() {
        return '' != $this->getDbInfo()->getClientDirectory();
    }

    public function getClientPath() {
        return $this->getDbInfo()->getClientDirectory();
    }

    public function begin_transaction()
    {
        $this->pdo->beginTransaction();
    }

    public function commit()
    {
        $this->pdo->commit();
    }

    public function rollback()
    {
        $this->pdo->rollback();
    }

    public function query($sql, $args = array(), $die = true)
    {
        $stmt = $this->pdo->prepare($sql);

        if ($stmt === false)
            throw new \Exception("Error: $sql: " . print_r($this->pdo->errorInfo(), 1));

        $success = $stmt->execute($args);

        if ($success === false || $stmt->errorCode() != 0) {
           throw new \Exception("Error: $sql: args:" . print_r($args,1).", err:".print_r($stmt->errorInfo(), 1));
        }

        return $stmt;
    }

    public function query_obj($sql, $args = array(), $class = "stdClass", $die = true)
    {
        $stmt = $this->query($sql, $args, $die);

        return $stmt->fetchObject($class);
    }

    public function query_list($sql, $args = array(), $class = "stdClass", $die = true)
    {
        $arr = array();

        $stmt = $this->query($sql, $args, $die);

        while ($stmt && $row = $stmt->fetchObject($class)) {

            $arr[] = $row;
        }

        return $arr;
    }

    public function quote($str)
    {
        return $this->pdo->quote($str);
    }

    public function fetch_array($stmt)
    {
        return $stmt->fetch(PDO::FETCH_BOTH);
    }

    public function num_rows($stmt)
    {
        return $stmt->rowCount();
    }

    public function fetch_column($stmt, $colnum = 0)
    {
        return $stmt->fetchColumn($colnum);
    }

    public function fetch_object($stmt, $class = "stdClass")
    {
        return $stmt->fetchObject($class);
    }

    public function get_insert_id()
    {
        return $this->pdo->lastInsertId();
    }
}
