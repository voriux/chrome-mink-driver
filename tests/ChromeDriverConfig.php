<?php

namespace DMore\ChromeDriverTests;

use Behat\Mink\Tests\Driver\AbstractConfig;
use DMore\ChromeDriver\ChromeDriver;

class ChromeDriverConfig extends AbstractConfig
{
    public static function getInstance()
    {
        return new self();
    }

    /**
     * {@inheritdoc}
     */
    public function createDriver()
    {
        return new ChromeDriver();
    }

    /**
     * {@inheritdoc}
     */
    protected function supportsCss()
    {
        return true;
    }
}
