<?php
namespace DMore\ChromeDriver;

use Behat\Mink\Exception\DriverException;
use WebSocket\ConnectionException;

class ChromeBrowser extends DevToolsConnection
{
    /** @var string */
    private $context_id;
    /** @var bool */
    private $headless = true;
    /** @var HttpClient */
    private $http_client;
    /** @var string */
    private $http_uri;

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
        if ($this->headless) {
            try {
                $versionInfo = json_decode($this->http_client->get($this->http_uri . '/json/version'));
                // handling chrome versions 62+ where Target.createBrowserContext moved to new location
                if(property_exists($versionInfo, 'webSocketDebuggerUrl')) {
                    $debugUrl = $versionInfo->webSocketDebuggerUrl;
                    $this->connect($debugUrl);
                }

                $this->context_id = $this->send('Target.createBrowserContext')['browserContextId'];
                $data = $this->send('Target.createTarget',
                    ['url' => 'about:blank', 'browserContextId' => $this->context_id]);
                $this->send('Target.activateTarget', ['targetId' => $data['targetId']]);
                return $data['targetId'];
            } catch (DriverException $exception) {
                if ($exception->getCode() == '-32601' || $exception->getCode() == '-32000') {
                    $this->headless = false;
                    return $this->start();
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
            if (!$this->send('Target.disposeBrowserContext', ['browserContextId' => $this->context_id])) {
                throw new ConnectionException('Unable to close browser context');
            }
        }
        parent::close();
    }

    protected function processResponse(array $data)
    {
        return false;
    }
}
