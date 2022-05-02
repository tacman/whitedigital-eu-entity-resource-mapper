# Entity Resource Mapper Bundle

Extends Symfony / Api Platform functionality by helping to map Doctrine entity objects with Api Platform resource objects and offers other helpers such as filters, etc.

## Configuration

### ClassMapper service ###
You should create ClassMapper service configuration file, for example:

```php
namespace App\Service;

use App\Dto\CustumerDto;
use App\Entity\Customer;

use WhiteDigital\EntityResourceMapperBundle\Mapper\ClassMapper;

class ClassMapperConfigurator
{
    public function __invoke(ClassMapper $classMapper)
    {
        $classMapper->registerMapping(CustomerResource::class, Customer::class);
    }
}

```
and register it as configurator for ClassMapper service in your services.yaml file:
```yaml
    WhiteDigital\EntityResourceMapper\Mapper\ClassMapper:
        configurator: '@App\Service\ClassMapperConfigurator'
```
### Doctrine ###

Doctrine configuration should be updated with mappings:

> **_TODO:_** Bundle should autoconfigure it
 
```yaml
                mappings:
                    App:
                        is_bundle: false
                        type: attribute
                        dir: '%kernel.project_dir%/src/Entity'
                        prefix: 'App\Entity'
                        alias: App
                    EntityResourceMapperBundle:
                        is_bundle: true
                        type: attribute
                        prefix: 'WhiteDigital\EntityResourceMapper\Entity'
                        alias: EntityResourceMapper
```
## Tests

Run tests by:
```bash
$ vendor/bin/phpunit
```

## TODO ##
- doctrine autoconfiguration
- how to call normalizer from static function from BaseEntity/Dto
- datetimenormalizer dependancy?
- Move Filters & other extensions
- What about pagination?
