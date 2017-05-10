<?php
namespace DMore\ChromeDriver;

use Behat\Mink\Driver\CoreDriver;
use Behat\Mink\Element\NodeElement;
use Behat\Mink\Exception\DriverException;
use Behat\Mink\Exception\ElementNotFoundException;
use Behat\Mink\Exception\UnsupportedDriverActionException;
use Behat\Mink\Session;
use WebSocket\Client;
use WebSocket\ConnectionException;

class ChromeDriver extends CoreDriver
{
    private $is_started = false;
    /** @var Client */
    private $client;
    /** @var string */
    private $url;
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
    /** @var string */
    private $base_url;
    /** @var array https://chromedevtools.github.io/devtools-protocol/tot/Network/#type-Response */
    private $response = null;

    /**
     * ChromeDriver constructor.
     * @param string $chrome_url
     * @param string $base_url
     * @param HttpClient $http_client
     */
    public function __construct(
        $chrome_url = 'http://localhost:9222',
        $base_url = 'http://localhost',
        HttpClient $http_client = null
    ) {
        if ($http_client == null) {
            $http_client = new HttpClient();
        }
        $this->http_client = $http_client;
        $this->url = $chrome_url;
        $this->base_url = $base_url;
    }

    public function start()
    {
        $json = $this->http_client->get($this->url . '/json/new');
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
        $this->http_client->get($this->url . '/json/close/' . $this->id);
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
     * @throws UnsupportedDriverActionException When operation not supported by the driver
     * @throws DriverException                  When the operation cannot be done
     */
    public function visit($url)
    {
        $this->response = null;
        $this->send('Page.navigate', ['url' => 'http://localhost' . $url]);
        $this->waitForPage();
    }

    /**
     * Returns current URL address.
     *
     * @return string
     *
     * @throws UnsupportedDriverActionException When operation not supported by the driver
     * @throws DriverException                  When the operation cannot be done
     */
    public function getCurrentUrl()
    {
        $url = $this->send('Runtime.evaluate', ['expression' => 'window.location.href'])['result']['value'];
        return str_replace($this->base_url, '', $url);
    }

    /**
     * Reloads current page.
     *
     * @throws UnsupportedDriverActionException When operation not supported by the driver
     * @throws DriverException                  When the operation cannot be done
     */
    public function reload()
    {
        $this->send('Page.reload');
        $this->waitForPage();
    }

    /**
     * Moves browser forward 1 page.
     *
     * @throws UnsupportedDriverActionException When operation not supported by the driver
     * @throws DriverException                  When the operation cannot be done
     */
    public function forward()
    {
        $this->send('Runtime.evaluate', ['expression' => 'window.history.forward()']);
        $this->wait(5000, "document.readyState == 'complete'");
    }

    /**
     * Moves browser backward 1 page.
     *
     * @throws UnsupportedDriverActionException When operation not supported by the driver
     * @throws DriverException                  When the operation cannot be done
     */
    public function back()
    {
        $this->send('Runtime.evaluate', ['expression' => 'window.history.back()']);
        $this->wait(5000, "document.readyState == 'complete'");
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
     * @throws UnsupportedDriverActionException When operation not supported by the driver
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
            $this->send('Runtime.evaluate', ['expression' => "document.cookie='$name=$value;$expiration; path=$path'"]);
        }
    }

    /**
     * Returns cookie by name.
     *
     * @param string $name
     *
     * @return string|null
     *
     * @throws UnsupportedDriverActionException When operation not supported by the driver
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
     * @throws UnsupportedDriverActionException When operation not supported by the driver
     * @throws DriverException                  When the operation cannot be done
     */
    public function getContent()
    {
        $frame = $this->send('Page.getResourceTree')['frameTree']['frame'];
        $parameters = ['frameId' => $frame['id'], 'url' => $frame['url']];
        return $this->send('Page.getResourceContent', $parameters)['content'];
    }

    /**
     * Capture a screenshot of the current window.
     *
     * @return string screenshot of MIME type image/* depending
     *                on driver (e.g., image/png, image/jpeg)
     *
     * @throws UnsupportedDriverActionException When operation not supported by the driver
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
        $result = $this->send('Runtime.evaluate', ['expression' => $expression])['result'];

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
        return $this->getElementProperty($xpath, 'tagName')['value'];
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
        $text = $this->getElementProperty($xpath, 'textContent')['value'];
        $text = trim(preg_replace('/\s+/', ' ', $text), ' ');
        return $text;
    }

    /**
     * {@inheritdoc}
     */
    public function getHtml($xpath)
    {
        return $this->getElementProperty($xpath, 'innerHTML')['value'];
    }

    /**
     * {@inheritdoc}
     */
    public function getOuterHtml($xpath)
    {
        return $this->getElementProperty($xpath, 'outerHTML')['value'];
    }

    /**
     * {@inheritdoc}
     */
    public function getAttribute($xpath, $name)
    {
        $name = addslashes($name);
        return $this->getElementProperty($xpath, "getAttribute('{$name}');")['value'];
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

        try {
            return $this->evaluateScript($expression);
        } catch (DriverException $exception) {
            # TODO: Don't assume it's always ElementNotFound. Get the script to throw something we can recognize
            # on php's side as NotFoundException
            throw new ElementNotFoundException($this);
        }
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
            element.dispatchEvent(new Event('keyup'))
        }
        element.dispatchEvent(new Event('change'))
    }
    result
JS;

        $result = $this->send('Runtime.evaluate', ['expression' => $expression])['result'];

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
        return $this->getElementProperty($xpath, 'checked')['value'];
    }

    /**
     * {@inheritdoc}
     */
    public function selectOption($xpath, $value, $multiple = false)
    {
        $this->expectSelectOrRadio($xpath);
        if ($multiple) {
            $value = array_merge((array) $value, $this->getValue($xpath));
        }
        return $this->setValue($xpath, $value);
    }

    /**
     * {@inheritdoc}
     */
    public function click($xpath)
    {
        $this->triggerMouseEvent($xpath, 'click');
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
        $this->triggerMouseEvent($xpath, 'dblclick');
    }

    /**
     * {@inheritdoc}
     */
    public function rightClick($xpath)
    {
        $this->triggerEvent($xpath, 'contextmenu');
    }

    /**
     * {@inheritdoc}
     */
    public function isVisible($xpath)
    {
        throw new UnsupportedDriverActionException('Element visibility check is not supported by %s', $this);
    }

    /**
     * {@inheritdoc}
     */
    public function isSelected($xpath)
    {
        throw new UnsupportedDriverActionException('Element selection check is not supported by %s', $this);
    }

    /**
     * {@inheritdoc}
     */
    public function mouseOver($xpath)
    {
        $this->triggerMouseEvent($xpath, 'mouseover');
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
        throw new UnsupportedDriverActionException('Mouse manipulations are not supported by %s', $this);
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

        $result = $this->send('Runtime.evaluate', ['expression' => $script])['result'];

        if (array_key_exists('subtype', $result) && $result['subtype'] === 'error') {
            if ($result['className'] === 'SyntaxError' && strpos($result['description'], 'Illegal return') !== false) {
                return $this->evaluateScript('(function() {' . $script . '}());');
            }
            throw new \Exception($result['description']);
        }

        if ($result['type'] === 'object' && $result['objectId']) {
            $parameters = ['objectId' => $result['objectId'], 'ownProperties' => true];
            $properties = $this->send('Runtime.getProperties', $parameters)['result'];
            $return = [];
            foreach ($properties as $property) {
                if ($property['name'] !== '__proto__' && $property['name'] !== 'length') {
                    $return[] = $property['value']['value'];
                }
            }
            return $return;
        }

        return $result['value'];
    }

    /**
     * {@inheritdoc}
     */
    public function wait($timeout, $condition)
    {
        $start = microtime(true);
        $end = $start + $timeout / 1000.0;
        do {
            $result = $this->evaluateScript($condition);
            usleep(100000);
        } while (microtime(true) < $end && !$result);
        return (bool)$result;
    }

    /**
     * {@inheritdoc}
     */
    public function resizeWindow($width, $height, $name = null)
    {
        throw new UnsupportedDriverActionException('Window resizing is not supported by %s', $this);
    }

    /**
     * {@inheritdoc}
     */
    public function maximizeWindow($name = null)
    {
        throw new UnsupportedDriverActionException('Window maximize is not supported by %s', $this);
    }

    /**
     * {@inheritdoc}
     */
    public function submitForm($xpath)
    {
        throw new UnsupportedDriverActionException('Form submission is not supported by %s', $this);
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
                        }
                        break;
                    case 'Network.responseReceived':
                        if ($data['params']['type'] == 'Document') {
                            $this->response = $data['params']['response'];
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
        $this->waitFor(function () {
            return $this->page_ready;
        });
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
        $expression = $this->getXpathExpression($xpath) . ' xpath_result.iterateNext().' . $property . '';
        $result = $this->send('Runtime.evaluate', ['expression' => $expression])['result'];
        if (array_key_exists('subtype', $result) && $result['subtype'] === 'error') {
            throw new ElementNotFoundException($this, null, $xpath);
        }
        return $result;
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
        $expression = $this->getXpathExpression($xpath);
        $expression .= <<<JS
    var element = xpath_result.iterateNext();
    element.tagName == 'SELECT' || (element.tagName == 'INPUT' && element.type == 'radio')
JS;
        if (!$this->evaluateScript($expression)) {
            throw new ElementNotFoundException($this, 'select', $xpath);
        }
    }

    /**
     * @param $xpath
     * @throws ElementNotFoundException
     */
    protected function expectCheckbox($xpath)
    {
        $expression = $this->getXpathExpression($xpath);
        $expression .= <<<JS
    var element = xpath_result.iterateNext();
    element.tagName == 'INPUT' && element.type == 'checkbox'
JS;
        if (!$this->evaluateScript($expression)) {
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
        $expression = $this->getXpathExpression($xpath);
        $expression .= <<<JS
    var element = xpath_result.iterateNext()
    if (element) {
        element.dispatchEvent(new MouseEvent('$event'));
    }
    element != null
JS;

        $result = $this->evaluateScript($expression);
        if (!$result) {
            throw new ElementNotFoundException($this, null, $xpath);
        }
    }

    /**
     * @param $xpath
     * @param $event
     * @throws ElementNotFoundException
     */
    protected function triggerEvent($xpath, $event)
    {
        $expression = $this->getXpathExpression($xpath);
        $expression .= <<<JS
    var element = xpath_result.iterateNext()
    if (element) {
        element.dispatchEvent(new Event('$event'));
    }
    element != null
JS;

        $result = $this->evaluateScript($expression);
        if (!$result) {
            throw new ElementNotFoundException($this, null, $xpath);
        }
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

        $expression = $this->getXpathExpression($xpath);
        $expression .= <<<JS
    var opts = $options;
    var element = xpath_result.iterateNext();
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


        $result = $this->evaluateScript($expression);
        if (!$result) {
            throw new ElementNotFoundException($this, null, $xpath);
        }
    }
}
