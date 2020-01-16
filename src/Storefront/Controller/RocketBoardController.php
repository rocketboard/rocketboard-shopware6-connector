<?php declare(strict_types=1);

namespace RocketBoard\Storefront\Controller;

use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\Framework\Context;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\Framework\Plugin\PluginCollection;
use Shopware\Core\System\SystemConfig\SystemConfigCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * @RouteScope(scopes={"storefront"})
 */
class RocketBoardController extends StorefrontController
{
    protected $apiVersion = "1.0";
    private $systemConfigService;
    /**
     * @var EntityRepositoryInterface
     */
    private $pluginRepo;
    /**
     * @var EntityRepositoryInterface
     */
    private $systemRepo;
    private $connection;
    /**
     * @var ParameterBagInterface
     */
    private $params;

    public function __construct(
        SystemConfigService $systemConfigService,
        EntityRepositoryInterface $pluginRepo,
        EntityRepositoryInterface $systemRepo,
        Connection $connection,
        ParameterBagInterface $params
    ) {
        $this->systemConfigService = $systemConfigService;
        $this->pluginRepo = $pluginRepo;
        $this->systemRepo = $systemRepo;
        $this->connection = $connection;
        $this->params = $params;
    }

    /**
     * @Route("/frontend/rocket_board/index", name="frontend.rocketboard.info", options={"seo"="false"}, methods={"GET"})
     */
    public function getShopInfo(Context $context): JsonResponse
    {
        /** @var Request $request */
        $request = $this->get('request_stack')->getCurrentRequest();

        $toUtf8 = $request->get('toUtf8', false);
        $what = $request->get('what', 'shopware');
        switch ($what) {
            case 'shopware':
                $data = $this->getAppInfo($what, $context);
                break;
            case 'plugins':
                $data = $this->getPluginInfo($what, $context);
                break;
        }

        $token = $this->systemConfigService->get('RocketBoard.config.rocketToken');
        $reqToken = $request->get('rocketToken', null);
        if ($token) {
            if (!$reqToken || $reqToken != $token) {
                die("Configuration token does not match parameter 'rocketToken'!");
            }
        } else {
            die("Please set a token in the configuration!");
        }

        if ($toUtf8) {
            $data = $this->utf8ize($data);
        }

        return new JsonResponse($data);
    }
    /**
     * Function to get all plugins and their versions.
     * @param string $what The type to return
     * @return array
     */
    public function getPluginInfo($what, $context)
    {
        $data = array();

        $data['type'] = $what;
        $data['version'] = $this->apiVersion;
        $data['data'] = [];

        $criteria = new Criteria();
        /** @var PluginCollection $plugins */
        $plugins = $this->pluginRepo->search($criteria, $context)->getEntities();

        foreach ($plugins as $plugin) {
            $data['data'][$plugin->getId()] = array(
                'name' => $plugin->getName(),
                'version' => $plugin->getVersion(),
                'author' => $plugin->getAuthor(),
                'active' => $plugin->getActive(),
            );
        }

        return $data;
    }
    /**
     * Function to get app info.
     * @param string $what The type to return
     * @return array
     */
    public function getAppInfo($what, $context)
    {
        $data = array();
        $data['version'] = $this->apiVersion;
        $data['type'] = $what;
        $data['application'] = [];
        $data['application']['type'] = "shopware";

        $data['application']['contact'] = $this->systemConfigService->get('core.basicInformation.email');
        $data['application']['name'] = $this->systemConfigService->get('core.basicInformation.shopName');
        $data['application']['version'] = $this->params->get('kernel.shopware_version');
        $data['application']['build'] = $this->params->get('kernel.shopware_version_revision');
        $proto = isset($_SERVER['HTTPS']) ? "https://" : "http://";
        $data['application']['url'] = $proto . $_SERVER['SERVER_NAME'];
        // TODO, see e.g. FirstRunWizardClient::getLicenseDomains()
        $data['application']['edition'] = 'CE';

        $data['infrastructure'] = [];
        $data['infrastructure']['platform'] = 'PHP ' . phpversion();
        $data['infrastructure']['os'] = $this->getOs() . " " . $this->getArch() . " " . $this->getDist() . " ";
        $data['infrastructure']['db'] = "MySQL " . $this->getMysqlVersion();
        $data['infrastructure']['web'] = $this->getServerSoftware();

        // different way to get all config vars
        $criteria = new Criteria();
        /** @var SystemConfigCollection $systemConfigCollection */
        $systemConfigCollection = $this->systemRepo->search($criteria, $context)->getEntities();
        // print_r($systemConfigCollection);
        foreach ($systemConfigCollection as $config) {
            // echo $config->getConfigurationKey() . " - " . $config->getConfigurationValue() . "<br/>";
        }
        return $data;
    }

    /**
     * @param $mixed
     *
     * @return array|bool|string
     */
    private function utf8ize($mixed)
    {
        if (is_array($mixed)) {
            foreach ($mixed as $key => $value) {
                $mixed[ $key ] = $this->utf8ize($value);
            }
        } elseif (is_string($mixed)) {
            return utf8_encode($mixed);
        }

        return $mixed;
    }

    /**
     * @return string
     */
    private function getOs()
    {
        return PHP_OS ?: '';
    }

    /**
     * @return string
     */
    private function getArch()
    {
        return php_uname('m') ?: '';
    }

    /**
     * @return string
     */
    private function getDist()
    {
        return php_uname('r') ?: '';
    }

    /**
     * @return string
     */
    private function getServerSoftware()
    {
        return isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : '';
    }

    /**
     * @return string
     */
    private function getMysqlVersion()
    {
        return (string) $this->connection->fetchColumn('SELECT @@version');
    }
}
