# Install and run Aleph

Aleph requires PHP 8.4.1 or newer, Laravel 13, the DOM and Zip PHP extensions, and a database
supported by Laravel. Install the package and create its tables:

```bash
composer require sifrious/aleph
php artisan migrate
```

Laravel package discovery loads `AlephServiceProvider` and the Funes provider. If package discovery
is disabled, register both providers in the host application.

## Register the connector

Add the shipped web configuration connector to the registry in the host's service provider:

```php
use Sifrious\Aleph\Connector\Configuration\WebCrawlConnector;
use Sifrious\Aleph\Connector\ConnectorRegistry;

public function boot(ConnectorRegistry $connectors, WebCrawlConnector $web): void
{
    $connectors->register($web);
}
```

## Configure the first source

Declare the same source bounds in `config/aleph.php`, then record the source through the command.
The command creates the source installation and sends the configuration declaration through Funes.

```bash
php artisan aleph:source:configure web-crawl example "Example site" \
  --value='seeds=["https://example.com/"]' \
  --value='allowed_hosts=["example.com"]' \
  --json
```

Keep the returned installation ID. Run and inspect the crawl:

```bash
php artisan aleph:crawl example
php artisan aleph:runs --json
php artisan aleph:source:show <installation-id> --json
```

The crawl completes only after Funes accepts the retrieved observation and its mechanical
extraction. `funes_observations` should now contain the configuration declaration and the retrieved
page. The documented flow is covered by `tests/Feature/FirstRunTest.php`.

Before deploying, run `php artisan aleph:upgrade:check`. It reports pending Aleph migrations,
missing top-level configuration, and contract violations on registered connectors.
