# ReportPortal agent for PHPUnit

PHPUnit listener that publishes test execution data to ReportPortal.

This package includes the ReportPortal HTTP client code that previously lived
in `reportportal/basic`, so consumers only need to install this package.

## Compatibility

- PHP 7.2 or newer
- PHPUnit 7.5, 8.5, or 9.6
- Guzzle HTTP client and Symfony YAML are installed as direct dependencies

PHPUnit 10 and newer replaced the legacy listener API with the event extension
API. Supporting those versions should be handled as a separate migration.

## Installation

Add the package to the project that runs PHPUnit:

```json
{
    "require-dev": {
        "reportportal/phpunit": "dev-master"
    },
    "minimum-stability": "dev",
    "prefer-stable": true
}
```

Then install dependencies:

```bash
composer update
```

## PHPUnit configuration

Register the listener in `phpunit.xml`:

```xml
<listeners>
    <listener class="AgentPHPUnit" file="vendor/reportportal/phpunit/src/AgentPHPUnit.php">
        <arguments>
            <string>reportportal-uuid</string>
            <string>https://reportportal.example.com</string>
            <string>project-name</string>
            <string>.000+00:00</string>
            <string>test launch name</string>
            <string>test launch description</string>
        </arguments>
    </listener>
</listeners>
```

Arguments:

1. ReportPortal UUID/API token
2. ReportPortal server URL
3. ReportPortal project name
4. Time zone suffix used by the ReportPortal client
5. Test launch name
6. Test launch description

See `ConfigFileExamples/phpUnitExampleConfigFile/phpunit.xml` for a complete
example.
