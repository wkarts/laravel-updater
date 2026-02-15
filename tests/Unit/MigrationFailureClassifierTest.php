<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Unit;

use Argws\LaravelUpdater\Migration\MigrationFailureClassifier;
use Exception;
use PHPUnit\Framework\TestCase;

class MigrationFailureClassifierTest extends TestCase
{
    public function testClassificaAlreadyExists(): void
    {
        $classifier = new MigrationFailureClassifier();
        $ex = new Exception("SQLSTATE[42S01]: Base table or view already exists: 1050 Table 'abertura_caixas' already exists", 1050);

        $this->assertSame(MigrationFailureClassifier::ALREADY_EXISTS, $classifier->classify($ex));
        $object = $classifier->inferObject($ex);
        $this->assertSame('table', $object['type']);
        $this->assertSame('abertura_caixas', $object['name']);
    }

    public function testClassificaLockRetryable(): void
    {
        $classifier = new MigrationFailureClassifier();
        $ex = new Exception('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock', 1213);

        $this->assertSame(MigrationFailureClassifier::LOCK_RETRYABLE, $classifier->classify($ex));
    }

    public function testClassificaNonRetryable(): void
    {
        $classifier = new MigrationFailureClassifier();
        $ex = new Exception('SQLSTATE[42000]: Syntax error or access violation: 1064 You have an error in your SQL syntax', 1064);

        $this->assertSame(MigrationFailureClassifier::NON_RETRYABLE, $classifier->classify($ex));
    }
}
