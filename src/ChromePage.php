<?php
namespace DMore\ChromeDriver;

use Behat\Mink\Exception\DriverException;

class ChromePage
{
    /** @var array */
    private $pending_requests = [];
    /** @var bool */
    private $page_ready = true;
    /** @var bool */
    private $has_javascript_dialog = false;
    /** @var array https://chromedevtools.github.io/devtools-protocol/tot/Network/#type-Response */
    private $response = null;
    private $connection;
    /** @var string[] */
    private $request_headers = [];
    private $browser_version;
    private $base_url;
    private $frames_pending_navigation = [];
    private $target_id;

    public function __construct(DevToolsConnection $connection, $browser_version, $base_url, $target_id)
    {
        $this->connection = $connection;
        $this->browser_version = $browser_version;
        $this->base_url = $base_url;

        $connection->on('event', [$this, 'handleEvent']);
        $this->target_id = $target_id;
    }

    public function connect()
    {
        $this->connection->connect();
        $this->connection->asyncSend('Page.enable');
        $this->connection->asyncSend('Network.enable');
        $this->connection->asyncSend('Animation.setPlaybackRate', ['playbackRate' => 100000]);
    }

    public function close()
    {
        $this->connection->asyncSend('Target.closeTarget', ['targetId' => $this->target_id]);
        $this->connection->close();
    }

    public function reset()
    {
        $this->response = null;
        $this->request_headers = [];
        $this->sendRequestHeaders();
    }

    public function visit($url)
    {
        $this->connection->asyncSend('Page.stopLoading');
        $this->response = null;
        $this->page_ready = false;
        $this->frames_pending_navigation = [];
        $this->pending_requests = [];
        $result = $this->connection->send('Page.navigate', ['url' => $url]);
        $frame_id = $result['frameId'] ?? $result['frame']['id'];
        $this->frames_pending_navigation[$frame_id] = $result;
    }

    public function reload()
    {
        $this->page_ready = false;
        $this->connection->asyncSend('Page.reload');
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

    public function getTabs()
    {
        $tabs = [];
        $targets = $this->connection->send('Target.getTargets');
        foreach ($targets['targetInfos'] as $tab) {
            if ($tab['type'] == 'page') {
                $tabs[] = $tab;
            }
        }
        return array_reverse($tabs, true);
    }

    protected function sendRequestHeaders()
    {
        $this->connection->asyncSend('Network.setExtraHTTPHeaders', ['headers' => $this->request_headers ?: new \stdClass()]);
    }

    private function waitForHttpResponse()
    {
        if (null === $this->response) {
            $parameters = ['expression' => 'document.readyState == "complete"'];
            $domReady = $this->connection->send('Runtime.evaluate', $parameters)['result']['value'];
            if (!$this->page_ready && $domReady) {
                if (null === $this->response) {
                    $this->response = [
                        'status' => 200,
                        'headers' => [],
                    ];
                    return;
                }
            }

            $this->waitForLoad();
        }
    }

    public function waitForLoad()
    {
        while (!$this->page_ready) {
            $this->connection->tick();
            if ($this->page_ready) {
                break;
            }
            usleep(1000);
        }
    }

    public function setDownloadBehavior($download_behavior)
    {
        $this->connection->asyncSend(
            'Page.setDownloadBehavior',
            $download_behavior
        );
    }

    public function setOverrideCertificateErrors($override_certificate_errors)
    {
        $this->connection->asyncSend('Security.enable');
        $this->connection->asyncSend('Security.setOverrideCertificateErrors', $override_certificate_errors);
    }

    /**
     * @param $script
     * @return null
     */
    public function runScript($script)
    {
        $this->waitForLoad();
        return $this->connection->send('Runtime.evaluate', ['expression' => $script]);
    }

    public function runAsyncScript($script)
    {
        $this->connection->asyncSend('Runtime.evaluate', ['expression' => $script]);
    }

    public function acceptAlert($text = '')
    {
        $this->connection->asyncSend('Page.handleJavaScriptDialog', ['accept' => true, 'promptText' => $text]);
    }

    public function dismissAlert()
    {
        $this->connection->asyncSend('Page.handleJavaScriptDialog', ['accept' => false]);
    }

    public function deleteAllCookies()
    {
        $this->connection->asyncSend('Network.clearBrowserCookies');
    }

    public function setCookie($name, $value)
    {
        if ($value === null) {
            foreach ($this->connection->send('Network.getAllCookies')['cookies'] as $cookie) {
                if ($cookie['name'] == $name) {
                    if ($this->browser_version >= 63) {
                        $parameters = ['name' => $name, 'url' => 'http://' . $cookie['domain'] . $cookie['path']];
                        $this->connection->asyncSend('Network.deleteCookies', $parameters);
                    } else {
                        $parameters = ['cookieName' => $name, 'url' => 'http://' . $cookie['domain'] . $cookie['path']];
                        $this->connection->asyncSend('Network.deleteCookie', $parameters);
                    }
                }
            }
        } else {
            $url = $this->base_url . '/';
            $value = urlencode($value);
            $this->connection->asyncSend('Network.setCookie', ['url' => $url, 'name' => $name, 'value' => $value]);
        }
    }

    public function getCookies()
    {
        return $this->connection->send('Network.getCookies');
    }

    public function setRequestHeader($name, $value)
    {
        $this->request_headers[$name] = $value;
        $this->sendRequestHeaders();
    }

    public function unsetRequestHeader($name)
    {
        if (array_key_exists($name, $this->request_headers)) {
            unset($this->request_headers[$name]);
            $this->sendRequestHeaders();
        }
    }

    public function captureScreenshot($options = [])
    {
        return $this->connection->send('Page.captureScreenshot', $options);
    }

    public function evaluateScript($script)
    {
        if (substr($script, 0, 8) === 'function') {
            $script = '(' . $script . ')';
            if (substr($script, -2) == ';)') {
                $script = substr($script, 0, -2) . ')';
            }
        }

        $result = $this->runScript($script)['result'];

        if (array_key_exists('subtype', $result) && $result['subtype'] === 'error') {
            if ($result['className'] === 'SyntaxError' && strpos($result['description'], 'Illegal return') !== false) {
                return $this->evaluateScript('(function() {' . $script . '}());');
            }
            if (preg_match('/Cannot read property .document. of null/', $result['description']) === 1) {
                throw new NoSuchFrameException('The iframe no longer exists');
            }
            throw new DriverException($result['description']);
        }

        if ($result['type'] == 'object' && array_key_exists('subtype', $result)) {
            if ($result['subtype'] == 'null') {
                return null;
            } elseif ($result['subtype'] == 'array' && $result['className'] == 'Array' && $result['objectId']) {
                return $this->fetchObjectProperties($result);
            } else {
                return [];
            }
        } elseif ($result['type'] == 'object' && $result['className'] == 'Object') {
            return $this->fetchObjectProperties($result);
        } elseif ($result['type'] == 'undefined') {
            return null;
        }

        if (!array_key_exists('value', $result)) {
            return null;
        }

        return $result['value'];
    }

    public function clearFocusedInput()
    {
        $parameters = ['type' => 'rawKeyDown', 'nativeVirtualKeyCode' => 8, 'windowsVirtualKeyCode' => 8];
        $this->connection->asyncSend('Input.dispatchKeyEvent', $parameters);
        $this->connection->asyncSend('Input.dispatchKeyEvent', ['type' => 'keyUp']);
    }

    /**
     * @param $value
     */
    public function simulateTyping($value)
    {
        $value = str_replace("\n", "\r", $value);
        for ($i = 0; $i < mb_strlen($value); $i++) {
            $char = mb_substr($value, $i, 1);
            $this->connection->asyncSend('Input.dispatchKeyEvent', ['type' => 'keyDown', 'text' => $char]);
            $command_id = $this->connection->asyncSend('Input.dispatchKeyEvent', ['type' => 'keyUp']);
        }

        if (isset($command_id)) {
            $this->connection->awaitResponse($command_id);
        }
    }

    public function attachFile($name, $path, $include_iframes) : bool
    {
        $this->connection->asyncSend('DOM.enable');
        $parameters = [
            'pierce' => $include_iframes,
        ];

        foreach ($this->connection->send('DOM.getFlattenedDocument', $parameters)['nodes'] as $element) {
            if (!empty($element['attributes'])) {
                $num_attributes = count($element['attributes']);
                for ($key = 0; $key < $num_attributes; $key += 2) {
                    if ($element['attributes'][$key] == 'name' && $element['attributes'][$key + 1] == $name) {
                        $this->connection->asyncSend('DOM.setFileInputFiles',
                            ['nodeId' => $element['nodeId'], 'files' => [$path]]);
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @param $result
     * @return array
     * @throws DriverException
     */
    protected function fetchObjectProperties($result)
    {
        $parameters = ['objectId' => $result['objectId'], 'ownProperties' => true];
        $properties = $this->connection->send('Runtime.getProperties', $parameters)['result'];
        $return = [];
        foreach ($properties as $property) {
            if ($property['name'] !== '__proto__' && $property['name'] !== 'length') {
                $value = $property['value'];
                if (!empty($value['type']) && $value['type'] == 'object' &&
                    !empty($value['className']) &&
                    in_array($value['className'], ['Array', 'Object'])
                ) {
                    $return[$property['name']] = $this->fetchObjectProperties($value);
                } else {
                    if (array_key_exists('value', $value)) {
                        $return[$property['name']] = $value['value'];
                    } else {
                        if ($value['type'] === 'number' && array_key_exists('unserializableValue', $value)) {
                            $return[$property['name']] = (int) $value['unserializableValue'];
                        } else {
                            throw new DriverException('Property value not set');
                        }
                    }
                }
            }
        }
        return $return;
    }

    public function getTargetInfo($id)
    {
        return $this->connection->send('Target.getTargetInfo', ['targetId' => $id])['targetInfo'];
    }

    /**
     * @param array $data
     * @throws DriverException
     */
    public function handleEvent(array $data)
    {
        switch ($data['method']) {
            case 'Network.requestWillBeSent':
                if ($data['params']['type'] == 'Document') {
                    $this->pending_requests[$data['params']['requestId']] = true;
                    $this->page_ready = false;
                }
                break;
            case 'Network.responseReceived':
                if ($data['params']['type'] == 'Document') {
                    unset($this->pending_requests[$data['params']['requestId']]);
                    $this->response = $data['params']['response'];
                    $this->updatePageStatus();
                }
                break;
            case 'Network.loadingFailed':
                if ($data['params']['canceled']) {
                    unset($this->pending_requests[$data['params']['requestId']]);
                    $this->updatePageStatus();
                }
                break;
            case 'Page.frameNavigated':
                $frame_id = $data['params']['frameId'] ?? $data['params']['frame']['id'];
                unset($this->frames_pending_navigation[$frame_id]);
                $this->updatePageStatus();
                break;
            case 'Page.frameStartedLoading':
            case 'Page.frameScheduledNavigation':
                $this->page_ready = false;
                $frame_id = $data['params']['frameId'] ?? $data['params']['frame']['id'];
                $this->frames_pending_navigation[$frame_id] = true;
                break;
            case 'Page.javascriptDialogOpening':
                $this->has_javascript_dialog = true;
                return;
            case 'Page.javascriptDialogClosed':
                $this->has_javascript_dialog = false;
                break;
            case 'Inspector.targetCrashed':
                throw new DriverException('Browser crashed');
                break;
            case 'Security.certificateError':
                if (isset($data['params']['eventId'])) {
                    $parameters = ['eventId' => $data['params']['eventId'], 'action' => 'continue'];
                    $this->connection->send('Security.handleCertificateError', $parameters);
                    $this->page_ready = false;
                }
                break;
            default:
                continue;
        }
    }

    public function moveMouse($left, $top, $sync = false)
    {
        $parameters = ['type' => 'mouseMoved', 'x' => $left, 'y' => $top, 'time' => time()];
        if ($sync) {
            $this->connection->send('Input.dispatchMouseEvent', $parameters);
        } else {
            $this->connection->asyncSend('Input.dispatchMouseEvent', $parameters);
        }
    }

    public function pressMouseButton($left, $top, $button = 'left', $click_count = null)
    {
        $parameters = ['type' => 'mousePressed', 'x' => $left, 'y' => $top, 'button' => $button, 'time' => time()];
        if (null !== $click_count) {
            $parameters['clickCount'] = $click_count;
        }
        $this->connection->asyncSend('Input.dispatchMouseEvent', $parameters);
    }

    public function releaseMouseButton($left, $top, $button = 'left', $click_count = null)
    {
        $parameters = ['type' => 'mouseReleased', 'x' => $left, 'y' => $top, 'button' => $button, 'time' => time()];
        if (null !== $click_count) {
            $parameters['clickCount'] = $click_count;
        }
        $this->connection->send('Input.dispatchMouseEvent', $parameters);
        # Give chrome 5ms to process the onclick handlers
        # If one of these triggered a new request (document or xhr), wait for it to complete before proceeding
        # So that subsequent actions happen on the new page
        usleep(5000);
        $this->waitForLoad();
    }

    public function setVisibleSize($width, $height)
    {
        $this->connection->asyncSend('Emulation.setDeviceMetricsOverride', [
            'width'             => $width,
            'height'            => $height,
            'deviceScaleFactor' => 0,
            'mobile'            => false,
            'fitWindow'         => false,
        ]);
        $this->connection->asyncSend('Emulation.setVisibleSize', [
            'width'  => $width,
            'height' => $height,
        ]);
    }

    public function maximize()
    {
        $parameters = ['windowId' => 1, 'bounds' => ['windowState' => 'maximized']];
        $this->connection->asyncSend('Browser.setWindowBounds', $parameters);
    }

    public function printToPdf($options)
    {
        return $this->connection->send('Page.printToPDF', $options);
    }

    private function updatePageStatus()
    {
        if (empty($this->pending_requests) && empty($this->frames_pending_navigation)) {
            $this->page_ready = true;
        }
    }
}
