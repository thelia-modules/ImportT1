<?php
namespace ImportT1\Controller\Admin;

use ImportT1\Import\AttributesImport;
use ImportT1\Import\BaseImport;
use ImportT1\Import\CategoriesImport;
use ImportT1\Import\ContentsImport;
use ImportT1\Import\CustomersImport;
use ImportT1\Import\FeaturesImport;
use ImportT1\Import\FoldersImport;
use ImportT1\Import\OrdersImport;
use ImportT1\Import\ProductsImport;
use ImportT1\ImportT1;
use ImportT1\Model\DatabaseInfo;
use ImportT1\Model\Db;
use Propel\Runtime\Propel;
use Thelia\Controller\Admin\BaseAdminController;
use Thelia\Core\HttpFoundation\Response;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Translation\Translator;
use Thelia\Log\Destination\TlogDestinationFile;
use Thelia\Log\Tlog;
use Thelia\Model\Map\ProductTableMap;
use Thelia\Model\ModuleQuery;
use Thelia\Module\BaseModule;

class ImportT1Controller extends BaseAdminController
{
    const RESOURCE_CODE = 'module.ImportT1';

    const MIN_VERSION = 142;

    protected $log_file;

    public function __construct()
    {

        $this->log_file = THELIA_LOG_DIR . DS . 'import-log.txt';

        // Set the current router ID, to use our parent's route methods on our routes
        $this->setCurrentRouter("router.ImportT1");

        $destination = "Thelia\Log\Destination\TlogDestinationFile";

        Tlog::getInstance()
            ->setLevel(Tlog::INFO)
            ->setDestinations($destination)
            ->setConfig($destination, TlogDestinationFile::VAR_PATH_FILE, $this->log_file)
            ->setFiles('*')
            ->setPrefix('[#LEVEL] #DATE #HOUR:');

        // Do not log requests
        Propel::getConnection(ProductTableMap::DATABASE_NAME)->useDebug(false);
    }

    public function indexAction()
    {
        if (null !== $response = $this->checkAuth(self::RESOURCE_CODE, array(), AccessManager::VIEW)) {
            return $response;
        }

        // Render the edition template.
        return $this->render('welcome');
    }

    public function checkDbAction()
    {
        if (null !== $response = $this->checkAuth(self::RESOURCE_CODE, array(), AccessManager::VIEW)) {
            return $response;
        }

        $error_message = false;

        $dbinfo = new DatabaseInfo();

        $dbinfo
            ->setHostname(trim($this->getRequest()->get('hostname')))
            ->setUsername(trim($this->getRequest()->get('username')))
            ->setPassword($this->getRequest()->get('password'))
            ->setDbname(trim($this->getRequest()->get('dbname')))
            ->setClientDirectory(trim($this->getRequest()->get('client_directory')));

        if (!$dbinfo->isValid()) {
            $error_message = Translator::getInstance()->trans("Please enter all required information.", [], ImportT1::DOMAIN);
        } else {
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
                        $error_message = $this->getTranslator()->trans(
                            "A Thelia %version database was found. Unfortunately, only Thelia 1.4.2 or newer databases may be imported. Please upgrade this Thelia 1 installation up to the latest available Thelia 1 version.",
                            array("%version" => rtrim(preg_replace("/(.)/", "$1.", $version), ".")),
                            ImportT1::DOMAIN
                        );
                    } else {
                        $dir = $dbinfo->getClientDirectory();

                        if (!empty($dir)) {
                            try {
                                // Check the "client" path
                                if (!is_dir($dbinfo->getClientDirectory())) {
                                    $error_message =
                                        $this->getTranslator()->trans(
                                            "The directory %dir was not found. Please check your input and try again.",
                                            array("%dir" => $dbinfo->getClientDirectory()),
                                            ImportT1::DOMAIN
                                        );
                                } else {
                                    $photos_dir = sprintf("%s%sgfx%sphotos", $dir, DS, DS);

                                    if (!is_dir($photos_dir)) {
                                        $error_message =
                                            $this->getTranslator()->trans(
                                                "No Thelia 1 image directory can be found in %dir directory.",
                                                array("%dir" => $dbinfo->getClientDirectory()),
                                                ImportT1::DOMAIN
                                            );
                                    }
                                }
                            } catch (\Exception $ex) {
                                $error_message = $this->getTranslator()->trans(
                                    "Failed to access to %dir directory. (%ex)",
                                    [
                                        "%dir" => $dbinfo->getClientDirectory(),
                                        '%ex' => $ex->getMessage()
                                    ],
                                    ImportT1::DOMAIN
                                );
                            }
                        }
                    }
                } catch (\Exception $ex) {
                    $error_message = $this->getTranslator()->trans(
                        "No Thelia 1 database was found in this database. Please check your input and try again. (%ex)",
                        [ '%ex' => $ex->getMessage() ],
                        ImportT1::DOMAIN
                    );
                }

            } catch (\Exception $ex) {
                $error_message = $this->getTranslator()->trans(
                    "Failed to connect to database using the parameters below. Please check your input and try again. (%ex)",
                    [ '%ex' => $ex->getMessage() ],
                    ImportT1::DOMAIN
                );
            }
        }

        if ($error_message !== false) {
            // Check that we have at least one payment and one delivery module
            if (null === ModuleQuery::create()->findOneByType(BaseModule::DELIVERY_MODULE_TYPE)) {
                $error_message = $this->getTranslator()->trans("No active delivery module was found. Please install and activate at least one delivery module.", [], ImportT1::DOMAIN);
            }
        }

        if ($error_message !== false) {
            // Check that we have at least one paypent and one delivery module
            // Find the first availables delivery and payment modules, that's the best we can do.
            if (null === ModuleQuery::create()->findOneByType(BaseModule::PAYMENT_MODULE_TYPE)) {
                $error_message = $this->getTranslator()->trans("No active paiement module was found. Please install and activate at least one payment module.", [], ImportT1::DOMAIN);
            }
        }

        if ($error_message !== false) {
            // Render the edition template.
            return $this->selectDbAction($error_message);
        }

        return $this->generateRedirectFromRoute("importT1.review");
    }

    /**
     * @return Db
     */
    protected function getDb()
    {
        return $this->container->get('importt1.db');
    }

    public function selectDbAction($error_message = false)
    {
        $dbinfo = $this->getDb()->getDbInfo();

        return $this->render(
            'select-db',
            array(
                'error_message' => $error_message,
                'hostname' => $dbinfo->getHostname(),
                'username' => $dbinfo->getUsername(),
                'password' => $dbinfo->getPassword(),
                'dbname' => $dbinfo->getDbname(),
                'client_directory' => $dbinfo->getClientDirectory()
            )
        );
    }

    public function reviewAction($error_message = false)
    {
        $dbinfo = $this->getDb()->getDbInfo();

        $db = $this->container->get('importt1.db');

        $db->connect();

        $hdl = $db->query("select valeur from variable where nom = 'version'");
        $version = $db->fetch_column($hdl);

        $hdl = $db->query("select valeur from variable where nom = 'nomsite'");
        $nomsite = $db->fetch_column($hdl);

        try {
            $db->query("select * from t1_t2_product limit 1");
            $alreadyDone = true;
        } catch (\Exception $ex) {
            $alreadyDone = false;
        }

        return $this->render(
            'review',
            array(
                'hostname' => $dbinfo->getHostname(),
                'dbname' => $dbinfo->getDbname(),
                'version' => rtrim(preg_replace("/(.)/", "$1.", $version), "."),
                'shop_name' => $nomsite,
                'client_directory' => $dbinfo->getClientDirectory(),
                'already_done' => $alreadyDone
            )
        );
    }

    public function showLogAction()
    {
        $resp = new Response();

        $resp->setContent(nl2br(file_get_contents($this->log_file)));

        return $resp;
    }

    /**
     * @param BaseImport $importer
     * @param $title
     * @param $object
     * @param $next_route
     * @param $startover_route
     * @param int $start
     * @param int $total_errors
     * @return Response
     */
    protected function genericImport($importer, $title, $object, $next_route, $startover_route, $start = 0, $total_errors = 0)
    {
        try {
            if ($start == 0) {
                $importer->preImport();
            }

            $total = $importer->getTotalCount();

            $result = $importer->import($start);

            $errors = $total_errors + $result->getErrors();

            $next_start = $start + $result->getCount();

            $remaining = max(0, $total - $next_start);

            if ($remaining == 0) {
                $importer->postImport();
            }

            return $this->render(
                'importer',
                array(
                    'object' => $object,
                    'title' => $title,
                    'chunk_size' => $importer->getChunkSize(),
                    'total' => $total,
                    'errors' => $errors,
                    'start' => $next_start,
                    'remaining' => $remaining,
                    'reload' => $remaining > 0,
                    'next_route' => $this->getRoute($next_route, array('start' => 0, 'total_errors' => $errors)),
                    'startover_route' =>  $this->getRoute($startover_route, array('start' => 0, 'total_errors' => $errors)),
                    'messages' => $this->getErrors(true)
                )
            );
        } catch (\Exception $ex) {
            Tlog::getInstance()->addError(sprintf("Failed in %s importation, error: ", $object), $ex);

            return $this->render(
                'import-error',
                [ 'error_message' => sprintf("Failed in %s importation. Error: %s. Please see log/import-log.txt for more details.", $object, $ex->getMessage()) ]
            );
        }
    }

    public function startupAction()
    {
        // This is the first import: let's clear the log file
        if ($fh = @fopen($this->log_file, 'w')) {
            fclose($fh);
        }

        Tlog::getInstance()->info("Started Thelia DB Import");

        // Start the first import
        return $this->generateRedirectFromRoute(
            'importT1.folder',
            [],
            ['start' => 0, 'total_errors' => 0]
        );
    }

    /**
     * @param int $start
     * @param int $total_errors
     * @return Response
     * @throws \Exception
     */
    public function importFoldersAction($start = 0, $total_errors = 0)
    {
        return $this->genericImport(
            new FoldersImport($this->getDispatcher(), $this->getDb()),
            $this->getTranslator()->trans("Folders importation"),
            'folder',
            'importT1.content',
            'importT1.folder',
            $start,
            $total_errors
        );
    }

    /**
     * @param int $start
     * @param int $total_errors
     * @return Response
     * @throws \Exception
     */
    public function importContentsAction($start = 0, $total_errors = 0)
    {
        return $this->genericImport(
            new ContentsImport($this->getDispatcher(), $this->getDb()),
            $this->getTranslator()->trans("Contents importation"),
            'content',
            'importT1.feature',
            'importT1.content',
            $start,
            $total_errors
        );
    }

    /**
     * @param int $start
     * @param int $total_errors
     * @return Response
     * @throws \Exception
     */
    public function importFeaturesAction($start = 0, $total_errors = 0)
    {
        return $this->genericImport(
            new FeaturesImport($this->getDispatcher(), $this->getDb()),
            $this->getTranslator()->trans("Features importation"),
            'feature',
            'importT1.attribute',
            'importT1.feature',
            $start,
            $total_errors
        );
    }

    /**
     * @param int $start
     * @param int $total_errors
     * @return Response
     * @throws \Exception
     */
    public function importAttributesAction($start = 0, $total_errors = 0)
    {
        return $this->genericImport(
            new AttributesImport($this->getDispatcher(), $this->getDb()),
            $this->getTranslator()->trans("Attributes importation"),
            'attribute',
            'importT1.categorie',
            'importT1.attribute',
            $start,
            $total_errors
        );
    }

    /**
     * @param int $start
     * @param int $total_errors
     * @return Response
     * @throws \Exception
     */
    public function importCategoriesAction($start = 0, $total_errors = 0)
    {
        return $this->genericImport(
            new CategoriesImport($this->getDispatcher(), $this->getDb()),
            $this->getTranslator()->trans("Categories importation"),
            'category',
            'importT1.product',
            'importT1.categorie',
            $start,
            $total_errors
        );
    }

    /**
     * @param int $start
     * @param int $total_errors
     * @return Response
     * @throws \Exception
     */
    public function importProductsAction($start = 0, $total_errors = 0)
    {
        return $this->genericImport(
            new ProductsImport($this->getDispatcher(), $this->getDb()),
            $this->getTranslator()->trans("Products importation"),
            'product',
            'importT1.customer',
            'importT1.product',
            $start,
            $total_errors
        );
    }

    /**
     * @param int $start
     * @param int $total_errors
     * @return Response
     * @throws \Exception
     */
    public function importCustomersAction($start = 0, $total_errors = 0)
    {
        if ($this->getRequest()->get('clearLog', false)) {
            // This is the first import: let's clear the log file
            if ($fh = @fopen($this->log_file, 'w')) {
                fclose($fh);
            }
        }

        return $this->genericImport(
            new CustomersImport($this->getDispatcher(), $this->getDb()),
            $this->getTranslator()->trans("Customer importation"),
            'customer',
            'importT1.order',
            'importT1.customer',
            $start,
            $total_errors
        );
    }

    /**
     * @param int $start
     * @param int $total_errors
     * @return Response
     * @throws \Exception
     */
    public function importOrdersAction($start = 0, $total_errors = 0)
    {
        return $this->genericImport(
            new OrdersImport($this->getDispatcher(), $this->getDb()),
            $this->getTranslator()->trans("Orders importation"),
            'order',
            'importT1.done',
            'importT1.order',
            $start,
            $total_errors
        );
    }

    public function importDoneAction($total_errors = 0)
    {
        $errors = "";

        if ($fh = fopen($this->log_file, 'r')) {
            while (false != $line = fgets($fh)) {
                $head = substr($line, 0, 4);
                if ($head == '[ERR' || $head == '[WAR') {
                    $errors .= $line;
                }
            }

            @fclose($fh);
        }

        Tlog::getInstance()->info(
            $this->getTranslator()->trans(
                "Thelia DB Import terminated with %err error(s)",
                array("%err", $total_errors)
            )
        );

        return $this->render(
            'done',
            array(
                'total_errors' => $total_errors,
                'errors' => nl2br($errors)
            )
        );
    }

    protected function getErrors($reverse)
    {
        $errors = array();

        if ($fh = fopen($this->log_file, 'r')) {
            while (false != $line = fgets($fh)) {
                $head = substr($line, 0, 4);
                if ($head == '[ERR' || $head == '[WAR') {
                    $errors[] = trim($line);
                }
            }

            @fclose($fh);
        }

        if ($reverse) {
            $errors = array_reverse($errors);
        }

        return nl2br(implode('<br />', $errors));
    }
}
