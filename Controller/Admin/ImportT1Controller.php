<?php
namespace ImportT1\Controller\Admin;

use Thelia\Controller\Admin\BaseAdminController;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Translation\Translator;
use ImportT1\Model\DatabaseInfo;
use Thelia\Model\Customer;
use Thelia\Core\Event\Customer\CustomerCreateOrUpdateEvent;
use Thelia\Model\CustomerTitleQuery;
use ImportT1\Import\CustomersImport;
use ImportT1\Import\CustomerTitleImport;
use Thelia\Log\Tlog;
use ImportT1\Import\CategoriesImport;
use ImportT1\Import\FeatAttrImport;
use ImportT1\Import\AttributesImport;
use ImportT1\Import\FeaturesImport;
use ImportT1\Import\FoldersImport;
use ImportT1\Import\ContentsImport;
use Thelia\Log\Destination\TlogDestinationFile;
use Symfony\Component\Filesystem\Filesystem;
use Thelia\Core\HttpFoundation\Response;
use ImportT1\Import\ProductsImport;

class ImportT1Controller extends BaseAdminController
{
    const RESOURCE_CODE = 'module.ImportT1';

    const MIN_VERSION = 151;

    protected $log_file;

    public function __construct() {

        $this->log_file = __DIR__.DS.'..'.DS.'..'.DS.'log'.DS.'import-log.txt';

        // Set the current router ID, to use our parent's route methods on our routes
        $this->setCurrentRouter("router.ImportT1");

        $destination = "Thelia\Log\Destination\TlogDestinationFile";

        Tlog::getInstance()
            ->setLevel(Tlog::INFO)
            ->setDestinations($destination)
            ->setConfig($destination, TlogDestinationFile::VAR_PATH_FILE, $this->log_file)
            ->setFiles('*')
            ->setPrefix('[#LEVEL] #DATE #HOUR:')
        ;
    }

    protected function getDb() {
        return $this->container->get('importt1.db');
    }

    public function indexAction()
    {
        if (null !== $response = $this->checkAuth(self::RESOURCE_CODE, array(), AccessManager::VIEW)) return $response;

        // Render the edition template.
        return $this->render('welcome');
    }

    public function checkDbAction()
    {
        if (null !== $response = $this->checkAuth(self::RESOURCE_CODE, array(), AccessManager::VIEW)) return $response;

        $error_message = false;

        $dbinfo = new DatabaseInfo();

        $dbinfo
            ->setHostname(trim($this->getRequest()->get('hostname')))
            ->setUsername(trim($this->getRequest()->get('username')))
            ->setPassword($this->getRequest()->get('password'))
            ->setDbname(trim($this->getRequest()->get('dbname')))
            ->setClientDirectory(trim($this->getRequest()->get('client_directory')))
        ;

        if (! $dbinfo->isValid()) {
            $error_message = Translator::getInstance()->trans("Please enter all required information.");
        }
        else {
            $this->getDb()->setDbInfo($dbinfo);

            // Try to connect to database
            try {
                $db = $this->container->get('importt1.db');

                $db->connect();

                // Check if we can find a Thelia database in this db
                try {
                    $db->query("select * from variable");

                    $hdl = $db->query("select valeur from variable where nom = 'version'");

                    $version = $db->fetch_column($hdl);

                    if (intval(substr($version, 0, 3)) < self::MIN_VERSION) {
                        $error_message = Translator::getInstance()->trans(
                                "A Thelia %version database was found. Unfortunately, only Thelia 1.5.1 or newer databases may be imported. Please upgrade this Thelia 1 installation up to the latest available Thelia 1 version.",
                                array("%version" => rtrim(preg_replace("/(.)/", "$1.", $version), "."))
                        );
                    }
                    else {

                        $dir = $dbinfo->getClientDirectory();

                        if (! empty($dir)) {

                            // Check the "client" path
                            if (! is_dir($dbinfo->getClientDirectory())) {
                                $error_message =
                                    Translator::getInstance()->trans(
                                        "The directory %dir was not found. Please check your input and try again.",
                                        array("%dir" => $dbinfo->getClientDirectory())
                                    );
                            }
                            else {
                                $photos_dir = sprintf("%s%sgfx%sphotos", $dir, DS, DS);

                                if (! is_dir($photos_dir)) {
                                    $error_message =
                                    Translator::getInstance()->trans(
                                            "No Thelia 1 image directory can be found in %dir directory.",
                                            array("%dir" => $dbinfo->getClientDirectory())
                                    );
                                }
                            }
                        }
                    }
                }
                catch (Exception $ex) {
                    $error_message = Translator::getInstance()->trans("No Thelia 1 database was found in this database. Please check your input and try again.");
                }

            } catch (\Exception $ex) {
                $error_message = Translator::getInstance()->trans("Failed to connect to database using the parameters below. Please check your input and try again");
            }
        }

        if ($error_message !== false) {
            // Render the edition template.
            return  $this->selectDbAction($error_message);
        }

        return $this->redirectToRoute("importT1.review");
    }

    public function selectDbAction($error_message = false) {

        $dbinfo = $this->getDb()->getDbInfo();

        return $this->render('select-db', array(
                'error_message' => $error_message,
                'hostname' => $dbinfo->getHostname(),
                'username' => $dbinfo->getUsername(),
                'password' => $dbinfo->getPassword(),
                'dbname'   => $dbinfo->getDbname(),
                'client_directory' => $dbinfo->getClientDirectory()
        ));
    }

    public function reviewAction($error_message = false) {

        $dbinfo = $this->getDb()->getDbInfo();

        $db = $this->container->get('importt1.db');

        $db->connect();

        $hdl     = $db->query("select valeur from variable where nom = 'version'");
        $version = $db->fetch_column($hdl);

        $hdl     = $db->query("select valeur from variable where nom = 'nomsite'");
        $nomsite = $db->fetch_column($hdl);

        return $this->render('review', array(
                'hostname'  => $dbinfo->getHostname(),
                'dbname'    => $dbinfo->getDbname(),
                'version'   => rtrim(preg_replace("/(.)/", "$1.", $version), "."),
                'shop_name' => $nomsite,
                'client_directory' => $dbinfo->getClientDirectory()
        ));
    }

    protected function genericImport($importer, $title, $object, $next_route, $startover_route, $start = 0, $errors = 0) {

        try {
            if ($start == 0) {
                $importer->preImport();
            }

            $total = $importer->getTotalCount();

            $result = $importer->import($start);

            $errors += $result->getErrors();

            $next_start = $start + $result->getCount();

            $remaining = max(0, $total - $next_start);

            if ($remaining == 0) {
                $importer->postImport();
            }

            return $this->render('importer', array(
                    'object'    => $object,
                    'title'     => $title,
                    'chunk_size'=> $importer->getChunkSize(),
                    'total'     => $total,
                    'errors'    => $errors,
                    'start'     => $next_start,
                    'remaining' => $remaining,
                    'reload'    => $remaining > 0,

                    'next_route'      => $next_route,
                    'startover_route' => $startover_route
            ));
        }
        catch (\Exception $ex) {
            throw $ex;
            /*
            Tlog::getInstance()->addError(sprintf("Failed in %s importation, error: ", $object), $ex);

            $this->render('import-error');
            */
        }
    }

    public function genericSingleStepImport($importer, $object, $next_route, $startover_route) {

        $importer->preImport();

        $result = $importer->import();

        $importer->postImport();

        return $this->render('single-step-importer', array(
                'object'          => $object,
                'total'           => $result->getCount(),
                'errors'          => $result->getErrors(),
                'next_route'      => $next_route,
                'startover_route' => $startover_route
        ));
    }

    public function showLogAction() {

        $resp = new Response();

        $resp->setContent(nl2br(file_get_contents($this->log_file)));

        return $resp;
    }

    public function importCustomersAction($start = 0, $errors = 0) {

        // This is the first import: let's clear the log file
        if ($start == 0)
            if ($fh = @fopen($this->log_file, 'w')) fclose($fh);

        return $this->genericImport(
                new CustomersImport($this->getDispatcher(), $this->getDb()),
                Translator::getInstance()->trans("Customer importation"),
                'customer',
                $this->getRoute('importT1.start.folders'),
                $this->getRoute('importT1.start.customers'),
                $start,
                $errors
        );
    }

    public function importFoldersAction($start = 0, $errors = 0) {

        return $this->genericImport(
                new FoldersImport($this->getDispatcher(), $this->getDb()),
                Translator::getInstance()->trans("Folders importation"),
                'folder',
                $this->getRoute('importT1.start.contents'),
                $this->getRoute('importT1.start.folders'),
                $start,
                $errors
        );
    }

    public function importContentsAction($start = 0, $errors = 0) {

        return $this->genericImport(
                new ContentsImport($this->getDispatcher(), $this->getDb()),
                Translator::getInstance()->trans("Contents importation"),
                'content',
                $this->getRoute('importT1.features'),
                $this->getRoute('importT1.start.contents'),
                $start,
                $errors
        );
    }

    public function importFeaturesAction($start = 0, $errors = 0) {

        return  $this->genericSingleStepImport(
                new FeaturesImport($this->getDispatcher(), $this->getDb()),
                'Features',
                $this->getRoute('importT1.attributes'),
                $this->getRoute('importT1.features')
        );
    }

    public function importAttributesAction($start = 0, $errors = 0) {

        return  $this->genericSingleStepImport(
                new AttributesImport($this->getDispatcher(), $this->getDb()),
                'Attributes',
                $this->getRoute('importT1.start.categories'),
                $this->getRoute('importT1.attributes')
        );
    }

    public function importCategoriesAction($start = 0, $errors = 0) {

        return $this->genericImport(
                new CategoriesImport($this->getDispatcher(), $this->getDb()),
                Translator::getInstance()->trans("Categories importation"),
                'category',
                $this->getRoute('importT1.start.products'),
                $this->getRoute('importT1.start.categories'),
                $start,
                $errors
        );
    }

    public function importProductsAction($start = 0, $errors = 0) {

        return $this->genericImport(
                new ProductsImport($this->getDispatcher(), $this->getDb()),
                Translator::getInstance()->trans("Products importation"),
                'product',
                $this->getRoute('importT1.start.orders'),
                $this->getRoute('importT1.start.products'),
                $start,
                $errors
        );
    }
}