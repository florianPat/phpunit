# PHPUnit-Parallel-Extension

PHPUnit-Parallel-Extension is an extension to PHPUnit with leverages the parallel extension to run test cases in parallel.

## Installation

Use [Composer](https://getcomposer.org/) to require the extension.

## Parameter

- nCores: Number of threads to start

## Configuration

Enable the extension in your phpunit.xml file:
```
<extensions>
    <bootstrap class="ParallelExtension\ExtensionBootstrap">
        <parameter name="nCores" value="2"/>
    </bootstrap>
</extensions>
```
