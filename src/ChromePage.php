<?php
namespace DMore\ChromeDriver;

use Behat\Mink\Exception\DriverException;
use WebSocket\Client;
use WebSocket\ConnectionException;

class ChromePage
{
    /** @var Client */
    private $client;
    /** @var int */
    private $command_id = 1;
    /** @var array */
    private $pending_requests;
    /** @var bool */
    private $page_ready = true;
    /** @var bool */
    private $has_javascript_dialog = false;
    /** @var array https://chromedevtools.github.io/devtools-protocol/tot/Network/#type-Response */
    private $response = null;
    /** @var string */
    private $url;

    public function __construct($url)
    {
        $this->url = $url;
    }

    public function connect()
    {
        $options = ['fragment_size' => 2000000]; # Chrome closes the connection if a message is sent in fragments
        $this->client = new Client($this->url, $options);
        $this->send('Page.enable');
        $this->send('DOM.enable');
        $this->send('Runtime.enable');
        $this->send('Network.enable');
        $this->send('Target.setDiscoverTargets', ['discover' => true]);
        $this->send('Target.setAutoAttach', ['autoAttach' => true, 'waitForDebuggerOnStart' => false]);
        $this->send('Target.setAttachToFrames', ['value' => true]);
    }

    public function close()
    {
        $this->client->close();
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

    /**
     * @param array $command
     * @param array $parameters
     * @return null|string
     * @throws \Exception
     */
    public function send($command, array $parameters = [])
    {
        $payload['id'] = $this->command_id++;
        $payload['method'] = $command;
        if (!empty($parameters)) {
            $payload['params'] = $parameters;
        }

        $this->client->send(json_encode($payload));

        $data = $this->waitFor(function ($data) use ($payload) {
            return array_key_exists('id', $data) && $data['id'] == $payload['id'];
        });

        if (isset($data['result'])) {
            return $data['result'];
        }

        return ['result' => ['type' => 'undefined']];
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

    private function waitFor(callable $is_ready)
    {
        $data = [];
        while (true) {
            try {
                $response = $this->client->receive();
            } catch (ConnectionException $exception) {
                $message = $exception->getMessage();
                $stream_state = json_decode(substr($message, strpos($message, '{')), true);
                if ($stream_state['timed_out'] == true && $stream_state['eof'] == false) {
                    continue;
                }

                throw $exception;
            }
            if (is_null($response)) {
                return null;
            }
            $data = json_decode($response, true);
            if (array_key_exists('error', $data)) {
                throw new DriverException($data['error']['message'], $data['error']['code']);
            }

            if (array_key_exists('method', $data)) {
                switch ($data['method']) {
                    case 'Page.javascriptDialogOpening':
                        $this->has_javascript_dialog = true;
                        break 2;
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

            if ($is_ready($data)) {
                break;
            }
        }

        return $data;
    }
}
