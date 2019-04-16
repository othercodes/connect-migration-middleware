# connect-migration-middleware
Small middleware to ease the service migration from legacy to Connect


## Installation

Install via ***composer***:

```json
{
    "require": {
      "ingrammicro/connect-migration-middleware": "*"
    }
}
```

## Usage 

Once we have the package installed we need to create a new service provider to inject the middleware 
into our connector. We need to provide some basic configuration to our migrations service in order to 
properly migrate the incoming old data.

### Configuration parameters

| Parameter       | Type            | Description                           |
| --------------- | --------------- | ------------------------------------- |
| logger          | \Connect\Logger | The logger instance of our connector. |
| migrationFlag   | string          | The name of the Connect parameter that stores the legacy data in json format. |
| transformations | array           | Assoc array with the connect param id as key and the rule to process the parameter value from the legacy data. |

```php
<?php

namespace App\Providers;

use Connect\Middleware\Migration;
use Connect\Runtime\ServiceProvider;
use Pimple\Container;
use Psr\Log\LoggerInterface;

/**
 * Class MigrationServiceProvider
 * @package App\Providers
 */
class MigrationServiceProvider extends ServiceProvider
{
    /**
     * Create a Migrate middleware
     * @param Container $container
     * @return Migration
     */
    public function register(Container $container)
    {
        return new Migration([
            'logger' => $container['logger'],
            'transformations' => [
                'email' => function ($migrationData, LoggerInterface $logger) {
                    $logger->info('Processing teamAdminEmail parameter.');
                    return strtolower($migrationData->teamAdminEmail);
                },
                'team_id' => function ($migrationData, LoggerInterface $logger) {
                    $logger->info('Processing email parameter.');
                    return strtolower($migrationData->teamId);
                },
                'team_name' => function ($migrationData, LoggerInterface $logger) {
                    $logger->info('Processing teamName parameter.');
                    return ucwords($migrationData->teamName);
                },
            ]
        ]);
    }
}
```

Next we need to add this service provider to our configuration json:

```json 
{
  "runtimeServices": {
    "migration": "\\App\\Providers\\MigrationServiceProvider",
  }  
}

```

Finally we only need to call the migration service inside our processRequest() method of 
the FulfillmentAutomation class:

```php

<?php

namespace App;

use Connect\Logger;
use Connect\Middleware\Migration;
use Connect\FulfillmentAutomation;

/**
 * Class ProductFulfillment
 * @package App
 * @property Logger $logger
 * @property Migration $migration
 */
class ProductFulfillment extends FulfillmentAutomation
 {
    public function processRequest($request)
    {
        switch ($request->type) {
            case "purchase":
                
                $request = $this->migration->migrate($request);
                
                // the migrate() method returns a new request object with the
                // migrated data populated, we only need to update the params 
                // and approve the fulfillment to complete the migration.
                
                $this->updateParameters($request, $request->asset->params);
                
                // more code...
        }

    }
    
    public function processTierConfigRequest($tierConfigRequest)
    {
        // NOT MIGRABLE!
    }
}
```

