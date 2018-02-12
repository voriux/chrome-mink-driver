<?php
namespace DMore\ChromeDriver;

use Behat\Mink\Exception\DriverException;
use WebSocket\ConnectionException;

class ChromeBrowser
{
    /** @var string */
    private $context_id;
    /** @var bool */
    private $headless = true;
    /** @var HttpClient */
    private $http_client;
    /** @var string */
    private $http_uri;
    /** @var string */
    private $version;
    /**
     * @var DevToolsConnection
     */
    private $connection;

    public function __construct(DevToolsConnection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @param HttpClient $client
     */
    public function setHttpClient(HttpClient $client)
    {
        $this->http_client = $client;
    }

    /**
     * @param string $http_uri
     */
    public function setHttpUri($http_uri)
    {
        $this->http_uri = $http_uri;
    }

    /**
     * @throws DriverException
     */
    public function start()
    {
        $versionInfo = json_decode($this->http_client->get($this->http_uri . '/json/version'));

        // Detect if Chrome is running
        if (null === $versionInfo) {
            throw new \RuntimeException(
                sprintf(
                    'Could not fetch version information from %s. Please check if Chrome is running.',
                    $this->http_uri.'/json/version'
                )
            );
        }

        // Detect Browser version
        if(property_exists($versionInfo, 'Browser')) {
            $start = strpos($versionInfo->Browser, '/') + 1;
            $this->version = (int) substr($versionInfo->Browser, $start, strpos($versionInfo->Browser, '.') - $start);
        }

        // Detect if Chrome has been started in Headless Mode
        if(property_exists($versionInfo, 'Browser') && strpos($versionInfo->Browser, 'Headless') === false) {
            $this->headless = false;
        }

        if ($this->headless) {
            try {
                $versionInfo = json_decode($this->http_client->get($this->http_uri . '/json/version'));
                // handling chrome versions 62+ where Target.createBrowserContext moved to new location
                if(property_exists($versionInfo, 'webSocketDebuggerUrl')) {
                    $debugUrl = $versionInfo->webSocketDebuggerUrl;
                    $this->connection = new DevToolsConnection($debugUrl);
                    $this->connection->connect();
                }

                $this->context_id = $this->connection->send('Target.createBrowserContext')['browserContextId'];
                $data = $this->connection->send('Target.createTarget',
                    ['url' => 'about:blank', 'browserContextId' => $this->context_id]);
                return $data['targetId'];
            } catch (DriverException $exception) {
                if ($exception->getCode() == '-32601' || $exception->getCode() == '-32000') {
                    $this->headless = false;
                } else {
                    throw $exception;
                }
            }
        }

        $json = $this->http_client->get($this->http_uri . '/json/new');
        $response = json_decode($json, true);
        return $response['id'];
    }

    public function close()
    {
        if ($this->headless) {
            if (!$this->connection->send('Target.disposeBrowserContext', ['browserContextId' => $this->context_id])) {
                throw new DriverException('Unable to close browser context');
            }
        }

        $this->connection->close();
    }

    /**
     * @return bool
     */
    public function isHeadless()
    {
        return $this->headless;
    }

    /**
     * @return string
     */
    public function getVersion()
    {
        return $this->version;
    }
}
