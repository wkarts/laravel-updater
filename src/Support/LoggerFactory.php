<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Support;

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

class LoggerFactory
{
    public static function make(array $config): LoggerInterface
    {
        $logger = new Logger('updater');
        $handler = new StreamHandler($config['path']);
        $handler->setFormatter(new JsonFormatter());
        $logger->pushHandler($handler);

        return $logger;
    }
}
