Chrome Mink Driver
==================

## Installation:

```bash
composer require dmore/chrome-mink-driver
```

## Usage:

```php
use Behat\Mink\Mink;
use Behat\Mink\Session;
use DMore\ChromeDriver\ChromeDriver;

use Selenium\Client as SeleniumClient;

$mink = new Mink(array(
    'chrome' => new Session(new ChromeDriver("http://localhost:9222")),
));

```

## With Behat:

```yaml
default:
    extensions:
        DMore\ChromeDriver\Behat\ChromeExtension: ~
        Behat\\MinkExtension:
            browser_name: chrome
            base_url: http://127.0.0.1
            sessions:
                default:
                    chrome:
                        api_url: "http://localhost:9222"
```
