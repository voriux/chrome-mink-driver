<?php
namespace DMore\ChromeDriver;

use Behat\Mink\Driver\CoreDriver;
use Behat\Mink\Exception\DriverException;
use Behat\Mink\Exception\ElementNotFoundException;
use Behat\Mink\Exception\UnsupportedDriverActionException;
use WebSocket\Client;
use WebSocket\ConnectionException;

class ChromeDriver extends CoreDriver
{
    private $is_started = false;
    /** @var Client */
    private $client;
    /** @var string */
    private $api_url;
    /** @var string */
    private $id;
    /** @var int */
    private $command_id = 1;
    /** @var HttpClient */
    private $http_client;
    /** @var bool */
    private $dom_ready;
    /** @var bool */
    private $page_ready;
    /** @var bool */
    private $node_ids_ready;
    /** @var array https://chromedevtools.github.io/devtools-protocol/tot/Network/#type-Response */
    private $response = null;
    /** @var string */
    private $current_url = null;

    /**
     * ChromeDriver constructor.
     * @param string $api_url
     * @param HttpClient $http_client
     */
    public function __construct($api_url = 'http://localhost:9222', HttpClient $http_client = null)
    {
        if ($http_client == null) {
            $http_client = new HttpClient();
        }
        $this->http_client = $http_client;
        $this->api_url = $api_url;
    }

    public function start()
    {
        $json = $this->http_client->get($this->api_url . '/json/new');
        $response = json_decode($json, true);
        $ws_url = $response['webSocketDebuggerUrl'];
        $this->id = $response['id'];
        $this->client = new Client($ws_url);
        $this->client->setFragmentSize(2000000); # Chrome closes the connection if a message is sent in fragments
        $this->send('Page.enable');
        $this->send('DOM.enable');
        $this->send('Runtime.enable');
        $this->send('Network.enable');
        $this->is_started = true;
    }

    /**
     * Checks whether driver is started.
     *
     * @return Boolean
     */
    public function isStarted()
    {
        return $this->is_started;
    }

    /**
     * Stops driver.
     *
     * Once stopped, the driver should be started again before using it again.
     *
     * Calling any action on a stopped driver is an undefined behavior.
     * The only supported method call after stopping a driver is starting it again.
     *
     * Calling stop on a stopped driver is an undefined behavior. Driver
     * implementations are free to handle it silently or to fail with an
     * exception.
     *
     * @throws DriverException When the driver cannot be closed
     */
    public function stop()
    {
        try {
            $this->client->close();
        } catch (\WebSocket\ConnectionException $exception) {
        }
        $this->http_client->get($this->api_url . '/json/close/' . $this->id);
        $this->is_started = false;
    }

    /**
     * Resets driver state.
     *
     * This should reset cookies, request headers and basic authentication.
     * When possible, the history should be reset as well, but this is not enforced
     * as some implementations may not be able to reset it without restarting the
     * driver entirely. Consumers requiring a clean history should restart the driver
     * to enforce it.
     *
     * Once reset, the driver should be ready to visit a page.
     * Calling any action before visiting a page is an undefined behavior.
     * The only supported method calls on a fresh driver are
     * - visit()
     * - setRequestHeader()
     * - setBasicAuth()
     * - reset()
     * - stop()
     *
     * Calling reset on a stopped driver is an undefined behavior.
     */
    public function reset()
    {
        $this->deleteAllCookies();
        $this->stop();
        $this->start();
    }

    /**
     * Visit specified URL.
     *
     * @param string $url url of the page
     *
     * @throws DriverException                  When the operation cannot be done
     */
    public function visit($url)
    {
        $this->response = null;
        $this->send('Page.navigate', ['url' => $url]);
        $this->page_ready = false;
        $this->waitForPage();
    }

    /**
     * Returns current URL address.
     *
     * @return string
     *
     * @throws DriverException                  When the operation cannot be done
     */
    public function getCurrentUrl()
    {
        return $this->current_url;
    }

    /**
     * Reloads current page.
     *
     * @throws DriverException                  When the operation cannot be done
     */
    public function reload()
    {
        $this->send('Page.reload');
        $this->page_ready = false;
        $this->waitForPage();
    }

    /**
     * Moves browser forward 1 page.
     *
     * @throws DriverException                  When the operation cannot be done
     */
    public function forward()
    {
        $this->runScript('window.history.forward()');
        $this->page_ready = false;
        $this->waitForPage();
    }

    /**
     * Moves browser backward 1 page.
     *
     * @throws DriverException                  When the operation cannot be done
     */
    public function back()
    {
        $this->runScript('window.history.back()');
        $this->page_ready = false;
        $this->waitForPage();
    }

    /**
     * {@inheritdoc}
     */
    public function setBasicAuth($user, $password)
    {
        throw new UnsupportedDriverActionException('Basic auth setup is not supported by %s', $this);
    }

    /**
     * {@inheritdoc}
     */
    public function switchToWindow($name = null)
    {
        throw new UnsupportedDriverActionException('Windows management is not supported by %s', $this);
    }

    /**
     * {@inheritdoc}
     */
    public function switchToIFrame($name = null)
    {
        throw new UnsupportedDriverActionException('iFrames management is not supported by %s', $this);
    }

    /**
     * {@inheritdoc}
     */
    public function setRequestHeader($name, $value)
    {
        $this->send('Network.setExtraHTTPHeaders', ['headers' => [$name => $value]]);
    }

    /**
     * {@inheritdoc}
     */
    public function getResponseHeaders()
    {
        $this->waitForHttpResponse();
        return $this->response['headers'];
    }

    /**
     * Sets cookie.
     *
     * @param string $name
     * @param string $value
     *
     * @throws DriverException                  When the operation cannot be done
     */
    public function setCookie($name, $value = null)
    {
        if ($value === null) {
            $expiration = 'expires=Thu, 01 Jan 1970 00:00:01 GMT';
            foreach ($this->send('Network.getAllCookies')['cookies'] as $cookie) {
                if ($name == $cookie['name']) {
                    $parameters = ['expression' => "document.cookie='$name=;$expiration; path={$cookie['path']}'"];
                    $this->send('Runtime.evaluate', $parameters);
                }
            }
        } else {
            $expiration = 'expires=' . date(DATE_COOKIE, time() + 86400);
            $value = urlencode($value);
            $current_url = $this->getCurrentUrl();
            $path = substr($current_url, strpos($current_url, '/') - 1);
            $name = urlencode($name);
            $this->runScript("document.cookie='$name=$value;$expiration; path=$path'");
        }
    }

    /**
     * Returns cookie by name.
     *
     * @param string $name
     *
     * @return string|null
     *
     * @throws DriverException                  When the operation cannot be done
     */
    public function getCookie($name)
    {
        $result = $this->send('Network.getCookies');

        foreach ($result['cookies'] as $cookie) {
            if ($cookie['name'] == $name) {
                return urldecode($cookie['value']);
            }
        }
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function getStatusCode()
    {
        $this->waitForHttpResponse();
        return $this->response['status'];
    }

    /**
     * Returns last response content.
     *
     * @return string
     *
     * @throws DriverException                  When the operation cannot be done
     */
    public function getContent()
    {
        return $this->getElementProperty('//body', 'textContent');
    }

    /**
     * Capture a screenshot of the current window.
     *
     * @return string screenshot of MIME type image/* depending
     *                on driver (e.g., image/png, image/jpeg)
     *
     * @throws DriverException                  When the operation cannot be done
     */
    public function getScreenshot()
    {
        return base64_decode($this->send('Page.captureScreenshot'));
    }

    /**
     * {@inheritdoc}
     */
    public function getWindowNames()
    {
        throw new UnsupportedDriverActionException('Listing all window names is not supported by %s', $this);
    }

    /**
     * {@inheritdoc}
     */
    public function getWindowName()
    {
        throw new UnsupportedDriverActionException('Listing this window name is not supported by %s', $this);
    }

    /**
     * Finds elements with specified XPath query.
     *
     * @param string $xpath
     * @return \Behat\Mink\Element\NodeElement[]
     * @throws ElementNotFoundException
     */
    public function findElementXpaths($xpath)
    {
        $expression = $this->getXpathExpression($xpath) . ' var items = 0; ' .
            'while (xpath_result.iterateNext()) { items++; }; items;';
        $result = $this->runScript($expression)['result'];

        $node_elements = [];

        for ($i = 1; $i <= $result['value']; $i++) {
            $node_elements[] = sprintf('(%s)[%d]', $xpath, $i);
        }
        return $node_elements;
    }

    /**
     * Returns element's tag name by it's XPath query.
     *
     * @param string $xpath
     * @return string
     * @throws ElementNotFoundException
     */
    public function getTagName($xpath)
    {
        return $this->getElementProperty($xpath, 'tagName');
    }

    /**
     * Returns element's text by it's XPath query.
     *
     * @param string $xpath
     * @return string
     * @throws ElementNotFoundException
     */
    public function getText($xpath)
    {
        $text = $this->getElementProperty($xpath, 'innerText');
        $text = trim(preg_replace('/\s+/', ' ', $text), ' ');
        return $text;
    }

    /**
     * {@inheritdoc}
     */
    public function getHtml($xpath)
    {
        return $this->getElementProperty($xpath, 'innerHTML');
    }

    /**
     * {@inheritdoc}
     */
    public function getOuterHtml($xpath)
    {
        return $this->getElementProperty($xpath, 'outerHTML');
    }

    /**
     * {@inheritdoc}
     */
    public function getAttribute($xpath, $name)
    {
        $name = addslashes($name);
        return $this->getElementProperty($xpath, "getAttribute('{$name}');");
    }

    /**
     * {@inheritdoc}
     */
    public function getValue($xpath)
    {
        $expression = $this->getXpathExpression($xpath);
        $expression .= <<<JS
        element = xpath_result.iterateNext();
    var value = null

    if (element.tagName == 'INPUT' && element.type == 'checkbox') {
        value = element.checked ? element.value : null;
    } else if (element.tagName == 'INPUT' && element.type == 'radio') {
        var name = element.getAttribute('name');
        if (name) {
            var fields = window.document.getElementsByName(name),
                i, l = fields.length;
            for (i = 0; i < l; i++) {
                var field = fields.item(i);
                if (field.form === element.form && field.checked) {
                    value = field.value;
                    break;
                }
            }
        }
    } else if (element.tagName == 'SELECT' && element.multiple) {
        value = []
        for (var i = 0; i < element.options.length; i++) {
            if (element.options[i].selected) {
                value.push(element.options[i].value);
            }
        }
    } else {
        value = element.value;
    }
    value
JS;

        return $this->evaluateScript($expression);
    }

    /**
     * {@inheritdoc}
     */
    public function setValue($xpath, $value)
    {
        $expression = $this->getXpathExpression($xpath);
        if (!ctype_digit($value)) {
            $value = json_encode($value);
        }
        $expression .= <<<JS
    var expected_value = $value;
    var element = xpath_result.iterateNext();
    var result = 0;
    if (!element) {
        result = 1
    } else {
        element.scrollIntoViewIfNeeded();
        if (element.tagName == 'INPUT' && element.type == 'radio') {
            var name = element.name
            var fields = window.document.getElementsByName(name),
                i, l = fields.length;
            for (i = 0; i < l; i++) {
                var field = fields.item(i);
                if (field.form === element.form) {
                    if (field.value === expected_value) {
                        field.checked = true;
                    } else {
                        field.checked = false;
                    }
                }
            }
        } else if (element.tagName == 'INPUT' && element.type == 'checkbox') {
            element.checked = expected_value;
        } else if (element.tagName == 'SELECT') {
            if (element.multiple && typeof expected_value != 'object') {
                expected_value = [expected_value]
            }
            for (var i = 0; i < element.options.length; i++) {
                if ((element.multiple && expected_value.includes(element.options[i].value)) || element.options[i].value == expected_value) {
                    element.options[i].selected = true;
                } else {
                    element.options[i].selected = false;
                }
            }
        } else if (element.tagName == 'INPUT' && element.type == 'file') {
        } else {
            element.value = expected_value;
            var keyup = document.createEvent("Events");
            keyup.initEvent("keyup", true, true);
            element.dispatchEvent(keyup)
        }
        var change = document.createEvent("Events");
        change.initEvent("change", true, true);
        element.dispatchEvent(change)
    }
    result
JS;

        $result = $this->runScript($expression)['result'];

        if ($result['type'] === 'number') {
            if ($result['value'] == 1) {
                throw new ElementNotFoundException($this, null, $xpath);
            }
        } elseif ($result['type'] == 'error') {
            throw new \Exception($result['description']);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function check($xpath)
    {
        $this->expectCheckbox($xpath);
        $this->setValue($xpath, true);
    }

    /**
     * {@inheritdoc}
     */
    public function uncheck($xpath)
    {
        $this->expectCheckbox($xpath);
        $this->setValue($xpath, false);
    }

    /**
     * {@inheritdoc}
     */
    public function isChecked($xpath)
    {
        return $this->getElementProperty($xpath, 'checked');
    }

    /**
     * {@inheritdoc}
     */
    public function selectOption($xpath, $value, $multiple = false)
    {
        $this->expectSelectOrRadio($xpath);
        if ($multiple) {
            $value = array_merge((array)$value, $this->getValue($xpath));
        }
        return $this->setValue($xpath, $value);
    }

    /**
     * {@inheritdoc}
     */
    public function click($xpath)
    {
        $this->mouseOver($xpath);
        $this->runScriptOnXpathElement($xpath, 'element.click()');
        $this->wait(5000, "document.readyState == 'complete'");
    }

    /**
     * {@inheritdoc}
     */
    public function attachFile($xpath, $path)
    {
        throw new UnsupportedDriverActionException('Attaching a file in an input is not supported by %s', $this);
    }

    /**
     * {@inheritdoc}
     */
    public function doubleClick($xpath)
    {
        $this->mouseOver($xpath);
        $this->triggerMouseEvent($xpath, 'dblclick');
    }

    /**
     * {@inheritdoc}
     */
    public function rightClick($xpath)
    {
        $this->mouseOver($xpath);
        $this->triggerEvent($xpath, 'contextmenu');
    }

    /**
     * {@inheritdoc}
     */
    public function isVisible($xpath)
    {
        return $this->runScriptOnXpathElement($xpath, 'element.offsetWidth > 0 && element.offsetHeight > 0;');
    }

    /**
     * {@inheritdoc}
     */
    public function isSelected($xpath)
    {
        return $this->runScriptOnXpathElement($xpath, '!!element.selected');
    }

    /**
     * {@inheritdoc}
     */
    public function mouseOver($xpath)
    {
        $this->runScriptOnXpathElement($xpath, 'element.scrollIntoViewIfNeeded()');
        list($left, $top) = $this->getCoordinatesForXpath($xpath);
        $this->send('Input.dispatchMouseEvent', ['type' => 'mouseMoved', 'x' => $left, 'y' => $top]);
    }

    /**
     * {@inheritdoc}
     */
    public function focus($xpath)
    {
        $this->triggerEvent($xpath, 'focus');
    }

    /**
     * {@inheritdoc}
     */
    public function blur($xpath)
    {
        $this->triggerEvent($xpath, 'blur');
    }

    /**
     * {@inheritdoc}
     */
    public function keyPress($xpath, $char, $modifier = null)
    {
        $this->triggerKeyboardEvent($xpath, $char, $modifier, 'keypress');
    }

    /**
     * {@inheritdoc}
     */
    public function keyDown($xpath, $char, $modifier = null)
    {
        $this->triggerKeyboardEvent($xpath, $char, $modifier, 'keydown');
    }

    /**
     * {@inheritdoc}
     */
    public function keyUp($xpath, $char, $modifier = null)
    {
        $this->triggerKeyboardEvent($xpath, $char, $modifier, 'keyup');
    }

    /**
     * {@inheritdoc}
     */
    public function dragTo($sourceXpath, $destinationXpath)
    {
        list($left, $top) = $this->getCoordinatesForXpath($sourceXpath);
        $this->send('Input.dispatchMouseEvent', ['type' => 'mouseMoved', 'x' => $left, 'y' => $top]);
        $parameters = ['type' => 'mousePressed', 'x' => $left, 'y' => $top, 'button' => 'left'];
        $this->send('Input.dispatchMouseEvent', $parameters);

        list($left, $top) = $this->getCoordinatesForXpath($destinationXpath);
        $this->send('Input.dispatchMouseEvent', ['type' => 'mouseMoved', 'x' => $left, 'y' => $top]);
        $parameters = ['type' => 'mouseReleased', 'x' => $left, 'y' => $top, 'button' => 'left'];
        $this->send('Input.dispatchMouseEvent', $parameters);
    }

    /**
     * {@inheritdoc}
     */
    public function executeScript($script)
    {
        $this->evaluateScript($script);
    }

    /**
     * {@inheritdoc}
     */
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
            throw new \Exception($result['description']);
        }

        if ($result['type'] == 'object' && array_key_exists('subtype', $result)) {
            if ($result['subtype'] == 'null') {
                return null;
            } elseif ($result['subtype'] == 'array' && $result['className'] == 'Array' && $result['objectId']) {
                return $this->fetchObjectProperties($result);
            } else {
                $parameters = ['objectId' => $result['objectId'], 'ownProperties' => true];
                $properties = $this->send('Runtime.getProperties', $parameters)['result'];
                return [];
            }
        } elseif ($result['type'] == 'object' && $result['className'] == 'Object') {
            return $this->fetchObjectProperties($result);
        } elseif ($result['type'] == 'undefined') {
            return null;
        }

        return $result['value'];
    }

    /**
     * {@inheritdoc}
     */
    public function wait($timeout, $condition)
    {
        $max_iterations = ceil($timeout / 10);
        $iterations = 0;

        do {
            $result = $this->evaluateScript($condition);
            if ($result || $iterations++ == $max_iterations) {
                break;
            }
            usleep(10000);
        } while (true);
        return (bool)$result;
    }

    /**
     * {@inheritdoc}
     */
    public function resizeWindow($width, $height, $name = null)
    {
        $this->executeScript("window.innerWidth = $width;window.innerHeight = $height;");
    }

    /**
     * {@inheritdoc}
     */
    public function maximizeWindow($name = null)
    {
        $this->executeScript("window.innerWidth = screen.width;window.innerHeight = screen.height;");
    }

    /**
     * {@inheritdoc}
     */
    public function submitForm($xpath)
    {
        $this->runScriptOnXpathElement($xpath, 'element.submit()');
    }

    private function waitFor(callable $is_ready)
    {
        do {
            $response = $this->client->receive();
            if (is_null($response)) {
                return null;
            }
            $data = json_decode($response, true);
            if (array_key_exists('error', $data)) {
                throw new DriverException($data['error']['message'], $data['error']['code']);
            }

            if (array_key_exists('method', $data)) {
                switch ($data['method']) {
                    case 'Network.requestWillBeSent':
                        if ($data['params']['type'] == 'Document') {
                            $this->response = null;
                            $this->current_url = $data['params']['request']['url'];
                        }
                        break;
                    case 'Network.responseReceived':
                        if ($data['params']['type'] == 'Document') {
                            $this->response = $data['params']['response'];
                            $this->current_url = $data['params']['response']['url'];
                        }
                        break;
                    case 'Page.domContentEventFired':
                        $this->dom_ready = false;
                        break;
                    case 'DOM.documentUpdated':
                        $this->dom_ready = true;
                        $this->node_ids_ready = false;
                        break;
                    case 'Page.frameNavigated':
                    case 'Page.loadEventFired':
                    case 'Page.frameStartedLoading':
                        $this->page_ready = false;
                        $this->dom_ready = false;
                        $this->node_ids_ready = false;
                        break;
                    case 'Page.frameStoppedLoading':
                        $this->page_ready = true;
                        break;
                    case 'DOM.setChildNodes':
                        $this->node_ids_ready = true;
                        break;
                    default:
                        continue;
                }
            }
        } while (!$is_ready($data));

        return $data;
    }

    /**
     * @param array $command
     * @param array $parameters
     * @return null|string
     * @throws \Exception
     */
    private function send($command, array $parameters = [])
    {
        $payload['id'] = $this->command_id++;
        $payload['method'] = $command;
        if (!empty($parameters)) {
            $payload['params'] = $parameters;
        }

        try {
            $this->client->send(json_encode($payload));
        } catch (ConnectionException $exception) {
            echo $exception->getMessage();
            echo '> ' . json_encode($payload) . PHP_EOL;
            exit;
        }

        $data = $this->waitFor(function ($data) use ($payload) {
            return array_key_exists('id', $data) && $data['id'] == $payload['id'];
        });

        return $data['result'];
    }

    private function waitForPage()
    {
        if (!$this->page_ready) {
            $this->waitFor(function () {
                return $this->page_ready;
            });
        }
    }

    protected function deleteAllCookies()
    {
        $this->send('Network.clearBrowserCookies');
    }

    /**
     * @param $xpath
     * @return string
     */
    protected function getXpathExpression($xpath):string
    {
        $xpath = addslashes($xpath);
        $xpath = str_replace("\n", '\\n', $xpath);
        return "var xpath_result = document.evaluate(\"{$xpath}\", document.body);";
    }

    protected function getElementProperty($xpath, $property)
    {
        return $this->runScriptOnXpathElement($xpath, 'element.' . $property);
    }

    protected function waitForHttpResponse()
    {
        if (null === $this->response) {
            $this->waitFor(function () {
                return null !== $this->response;
            });
        }
    }

    /**
     * @param $xpath
     * @throws ElementNotFoundException
     */
    protected function expectSelectOrRadio($xpath)
    {
        $script = <<<JS
    element.tagName == 'SELECT' || (element.tagName == 'INPUT' && element.type == 'radio')
JS;
        if (!$this->runScriptOnXpathElement($xpath, $script)) {
            throw new ElementNotFoundException($this, 'checkbox', $xpath);
        }
    }

    /**
     * @param $xpath
     * @throws ElementNotFoundException
     */
    protected function expectCheckbox($xpath)
    {
        $script = <<<JS
    element.tagName == 'INPUT' && element.type == 'checkbox'
JS;
        if (!$this->runScriptOnXpathElement($xpath, $script)) {
            throw new ElementNotFoundException($this, 'checkbox', $xpath);
        }
    }

    /**
     * @param $xpath
     * @param $event
     * @throws ElementNotFoundException
     */
    protected function triggerMouseEvent($xpath, $event)
    {
        $script = <<<JS
    if (element) {
        element.dispatchEvent(new MouseEvent('$event'));
    }
    element != null
JS;

        $this->runScriptOnXpathElement($xpath, $script);
    }

    /**
     * @param $xpath
     * @param $event
     * @throws ElementNotFoundException
     */
    protected function triggerEvent($xpath, $event)
    {
        $script = <<<JS
    if (element) {
        element.dispatchEvent(new Event('$event'));
    }
    element != null
JS;

        $this->runScriptOnXpathElement($xpath, $script);
    }

    /**
     * @param $xpath
     * @param $char
     * @param $modifier
     * @param $event
     * @throws ElementNotFoundException
     */
    protected function triggerKeyboardEvent($xpath, $char, $modifier, $event)
    {
        if (is_string($char)) {
            $char = ord($char);
        }
        $options = [
            'ctrlKey' => $modifier == 'ctrl' ? 'true' : 'false',
            'altKey' => $modifier == 'alt' ? 'true' : 'false',
            'shiftKey' => $modifier == 'shift' ? 'true' : 'false',
            'metaKey' => $modifier == 'meta' ? 'true' : 'false',
        ];

        $script = <<<JS
    var opts = $options;
    if (element) {
        element.focus();
        var event = document.createEvent("Events");
        event.initEvent("$event", true, true);
        event.key = $char;
        event.keyCode = $char;
        event.which = $char;
        event.ctrlKey = {$options['ctrlKey']};
        event.shiftKey = {$options['shiftKey']};
        event.altKey = {$options['altKey']};
        event.metaKey = {$options['metaKey']};

        element.dispatchEvent(event);
    }
    element != null;
JS;

        $this->runScriptOnXpathElement($xpath, $script);
    }

    /**
     * @param $xpath
     * @param $script
     * @return array
     * @throws ElementNotFoundException
     * @throws \Exception
     */
    protected function runScriptOnXpathElement($xpath, $script)
    {
        $expression = $this->getXpathExpression($xpath);
        $expression .= <<<JS
    var element = xpath_result.iterateNext();
    if (null == element) {
        throw new Error("Element not found");
    }
JS;
        $expression .= $script;
        try {
            $result = $this->evaluateScript($expression);
        } catch (\Exception $exception) {
            if (strpos($exception->getMessage(), 'Element not found') !== false) {
                throw new ElementNotFoundException($this, null, $xpath);
            }
            throw $exception;
        }
        return $result;
    }

    /**
     * @param $xpath
     * @return array
     */
    protected function getCoordinatesForXpath($xpath):array
    {
        $expression = $this->getXpathExpression($xpath);
        $expression .= <<<JS
    var element = xpath_result.iterateNext();
    rect = element.getBoundingClientRect();
    [rect.left, rect.top]
JS;

        list($left, $top) = $this->evaluateScript($expression);
        $left = round($left + 1);
        $top = round($top + 1);
        return array($left, $top);
    }

    /**
     * @param $script
     * @return null
     */
    protected function runScript($script)
    {
        $this->waitForPage();
        return $this->send('Runtime.evaluate', ['expression' => $script]);
    }

    /**
     * @param $result
     * @return array
     */
    protected function fetchObjectProperties($result):array
    {
        $parameters = ['objectId' => $result['objectId'], 'ownProperties' => true];
        $properties = $this->send('Runtime.getProperties', $parameters)['result'];
        $return = [];
        foreach ($properties as $property) {
            if ($property['name'] !== '__proto__' && $property['name'] !== 'length') {
                if (!empty($property['value']['type']) && $property['value']['type'] == 'object' &&
                    !empty($property['value']['className']) &&
                    in_array($property['value']['className'], ['Array', 'Object'])
                ) {
                    $return[$property['name']] = $this->fetchObjectProperties($property['value']);
                } else {
                    $return[$property['name']] = $property['value']['value'];
                }
            }
        }
        return $return;
    }
}
