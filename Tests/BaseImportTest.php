<?php
namespace ImportT1\Tests;

use ImportT1\Import\BaseImport;
use ImportT1\Model\DatabaseInfo;
use ImportT1\Model\Db;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Thelia\Core\HttpFoundation\Request;
use Thelia\Core\HttpFoundation\Session\Session;

/**
 * Class BaseImportTest
 * @package ImportT1\Tests
 * @author Franck Allimant <franck@cqfdev.fr>
 */
class BaseImportTest extends \PHPUnit_Framework_TestCase
{
    private $request;
    private $dispatcher;
    private $thelia1_db;

    public function setUp()
    {
        $this->dispatcher = $this->getMock("Symfony\Component\EventDispatcher\EventDispatcherInterface");

        $session = new Session(new MockArraySessionStorage());

        $this->request = new Request();
        $this->request->setSession($session);

        $dbinfo = new DatabaseInfo();

        $dbinfo->setHostname('localhost');
        $dbinfo->setDbname('thelia1543');
        $dbinfo->setUsername('thelia1543');
        $dbinfo->setPassword('honolulu');

        $this->thelia1_db = new Db($this->request);

        $this->thelia1_db->setDbInfo($dbinfo);
    }

    public function testGetLang()
    {
        $bi = new BaseImport($this->dispatcher, $this->thelia1_db);

        $bi->getT2Lang(1);
    }

    public function testGetCountry()
    {
        $bi = new BaseImport($this->dispatcher, $this->thelia1_db);

        $res = $bi->getT2Country(64);
    }
}
