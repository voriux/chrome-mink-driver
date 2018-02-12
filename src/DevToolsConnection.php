<?php

namespace DMore\ChromeDriver;

use Behat\Mink\Exception\DriverException;
use Evenement\EventEmitterInterface;
use Evenement\EventEmitterTrait;
use Ratchet\Client\Connector;
use Ratchet\Client\WebSocket;
use Ratchet\RFC6455\Messaging\MessageInterface;
use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;

class DevToolsConnection implements EventEmitterInterface
{
    use EventEmitterTrait;

    /** @var int */
    private $command_id = 1;
    /** @var string */
    private $url;
    /** @var bool */
    private $connected = false;
    private $connecting = false;
    /** @var WebSocket */
    private $socket;
    protected $response_queue = [];
    /** @var LoopInterface */
    private $loop;

    public function __construct($url)
    {
        $this->url = $url;
    }

    public function connect($url = null)
    {
        $this->connecting = true;
        $url = $url == null ? $this->url : $url;
        $this->loop = Factory::create();

        $connector = new Connector($this->loop);
        $connect = $connector($url, [], []);
        $connect->then(function (WebSocket $socket) {
            $this->connected = true;
            $this->socket = $socket;

            $socket->on('message', [$this, 'receive']);
            $socket->on('close', [$this, 'close']);
        });
    }

    public function close()
    {
        $this->connecting = false;
        $this->connected = false;
        $this->loop->stop();
        $this->socket->close();
    }

    /**
     * @param string $command
     * @param array $parameters
     * @return array
     * @throws \Exception
     */
    public function send($command, array $parameters = [])
    {
        $command_id = $this->asyncSend($command, $parameters);
        $response = $this->awaitResponse($command_id);

        return $response['result'];
    }

    public function asyncSend($command, array $parameters = []) : int
    {
        while ($this->connecting) {
            $this->loop->tick();
            if ($this->connected) {
                break;
            }
            usleep(1000);
        }

        if (!$this->connected) {
            throw new DriverException('No connection');
        }

        $payload['id'] = $this->command_id++;
        $payload['method'] = $command;

        if (!empty($parameters)) {
            $payload['params'] = $parameters;
        }

        $this->socket->send(json_encode($payload));

        return $payload['id'];
    }

    /**
     * @param $command_id
     * @return array
     */
    public function awaitResponse($command_id): array
    {
        $response = [];
        $listener = function ($data) use (&$response, $command_id) {
            if ($data['id'] == $command_id) {
                $response = $data;
            }
        };

        $this->on('response', $listener);

        do {
            usleep(200);
            $this->tick();
        } while ([] === $response);

        $this->removeListener('response', $listener);
        return $response;
    }

    public function tick()
    {
        $this->loop->tick();
    }

    public function receive(MessageInterface $message)
    {
        $data = json_decode($message->getPayload(), true);

        if (array_key_exists('id', $data)) {
            $this->emit('response', [$data]);
        } elseif (array_key_exists('method', $data)) {
            $this->emit('event', [$data]);
        } else {
            throw new \Exception("Can't handle '{$message->getPayload()}'");
        }
    }
}
