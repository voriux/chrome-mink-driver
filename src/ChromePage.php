<?php
namespace DMore\ChromeDriver;

use Behat\Mink\Exception\DriverException;
use WebSocket\ConnectionException;

class ChromePage extends DevToolsConnection
{
    /** @var array */
    private $pending_requests;
    /** @var bool */
    private $page_ready = true;
    /** @var bool */
    private $has_javascript_dialog = false;
    /** @var array https://chromedevtools.github.io/devtools-protocol/tot/Network/#type-Response */
    private $response = null;

    public function connect()
    {
        parent::connect();
        $this->send('Page.enable');
        $this->send('DOM.enable');
        $this->send('Runtime.enable');
        $this->send('Network.enable');
        $this->send('Target.setDiscoverTargets', ['discover' => true]);
        $this->send('Target.setAutoAttach', ['autoAttach' => true, 'waitForDebuggerOnStart' => false]);
        $this->send('Target.setAttachToFrames', ['value' => true]);
    }

    public function reset()
    {
        $this->response = null;
    }

    public function visit($url)
    {
        $this->response = null;
        $this->page_ready = false;
        $this->send('Page.navigate', ['url' => $url]);
    }

    public function reload()
    {
        $this->send('Page.reload');
        $this->page_ready = false;
    }

    public function waitForLoad()
    {
        if (!$this->page_ready) {
            try {
                $this->waitFor(function () {
                    return $this->page_ready;
                });
            } catch (ConnectionException $exception) {
                throw new DriverException("Page not loaded");
            }
        }
    }

    public function getResponse()
    {
        $this->waitForHttpResponse();
        return $this->response;
    }

    /**
     * @return boolean
     */
    public function hasJavascriptDialog()
    {
        return $this->has_javascript_dialog;
    }

    private function waitForHttpResponse()
    {
        if (null === $this->response) {
            $this->waitFor(function () {
                return null !== $this->response && count($this->pending_requests) == 0;
            });
        }
    }

    /**
     * @param array $data
     * @return bool
     * @throws DriverException
     */
    protected function processResponse(array $data)
    {
        if (array_key_exists('error', $data)) {
            throw new DriverException($data['error']['message'], $data['error']['code']);
        }

        if (array_key_exists('method', $data)) {
            switch ($data['method']) {
                case 'Page.javascriptDialogOpening':
                    $this->has_javascript_dialog = true;
                    return true;
                case 'Network.requestWillBeSent':
                    if ($data['params']['type'] == 'Document') {
                        $this->pending_requests[$data['params']['requestId']] = true;
                    }
                    break;
                case 'Network.responseReceived':
                    if ($data['params']['type'] == 'Document') {
                        unset($this->pending_requests[$data['params']['requestId']]);
                        $this->response = $data['params']['response'];
                    }
                    break;
                case 'Network.requestServedFromCache':
                    unset($this->pending_requests[$data['params']['requestId']]);
                    break;
                case 'Page.frameNavigated':
                case 'Page.loadEventFired':
                case 'Page.frameStartedLoading':
                    $this->page_ready = false;
                    break;
                case 'Page.frameStoppedLoading':
                    $this->page_ready = true;
                    break;
                default:
                    continue;
            }
        }

        return false;
    }
}
