<?php
namespace DMore\ChromeDriver;

use WebSocket\Client;
use WebSocket\ConnectionException;

abstract class DevToolsConnection
{
    /** @var Client */
    private $client;
    /** @var int */
    private $command_id = 1;
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
    }

    public function close()
    {
        $this->client->close();
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

    protected function waitFor(callable $is_ready)
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

            if ($this->processResponse($data)) {
                break;
            }

            if ($is_ready($data)) {
                break;
            }
        }

        return $data;
    }

    abstract protected function processResponse(array $data);
}
