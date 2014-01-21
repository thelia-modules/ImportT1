<?php
namespace ImportT1\Model;

class DatabaseInfo
{
    protected $hostname;
    protected $username;
    protected $password;
    protected $dbname;
    protected $client_directory;

    public function getHostname()
    {
        return $this->hostname;
    }

    public function setHostname($hostname)
    {
        $this->hostname = $hostname;

        return $this;
    }

    public function getUsername()
    {
        return $this->username;
    }

    public function setUsername($username)
    {
        $this->username = $username;

        return $this;
    }

    public function getPassword()
    {
        return $this->password;
    }

    public function setPassword($password)
    {
        $this->password = $password;

        return $this;
    }

    public function getDbname()
    {
        return $this->dbname;
    }

    public function setDbname($dbname)
    {
        $this->dbname = $dbname;

        return $this;
    }

    public function getClientDirectory()
    {
        return $this->client_directory;
    }

    public function setClientDirectory($client_directory)
    {
        $this->client_directory = $client_directory;
    }

    public function isValid()
    {
        return !(empty($this->hostname) || empty($this->username) || empty($this->dbname));
    }

}
