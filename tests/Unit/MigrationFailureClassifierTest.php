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
        $context = $classifier->classifyWithContext($ex);
        $this->assertSame('42s01', $context['sqlstate']);
        $this->assertSame(1050, $context['errno']);

        $object = $classifier->inferObject($ex);
        $this->assertSame('table', $object['type']);
        $this->assertSame('abertura_caixas', $object['name']);
    }

    public function testClassificaDuplicateConstraint1826ComoAlreadyExists(): void
    {
        $classifier = new MigrationFailureClassifier();
        $ex = new Exception('SQLSTATE[HY000]: General error: 1826 Duplicate foreign key constraint name fk_orders_customer');

        $this->assertSame(MigrationFailureClassifier::ALREADY_EXISTS, $classifier->classify($ex));
    }

    public function testClassificaErrno121ConstraintComoAlreadyExists(): void
    {
        $classifier = new MigrationFailureClassifier();
        $ex = new Exception('SQLSTATE[HY000]: General error: 121 Duplicate key on write or update - constraint fk_orders_customer already exists', 121);

        $this->assertSame(MigrationFailureClassifier::ALREADY_EXISTS, $classifier->classify($ex));
    }

    public function testClassificaLockRetryable(): void
    {
        $classifier = new MigrationFailureClassifier();
        $ex = new Exception('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock', 1213);

        $this->assertSame(MigrationFailureClassifier::LOCK_RETRYABLE, $classifier->classify($ex));
    }

    public function testClassificaMetadataLockRetryable(): void
    {
        $classifier = new MigrationFailureClassifier();
        $ex = new Exception('SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction due metadata lock', 1205);

        $this->assertSame(MigrationFailureClassifier::LOCK_RETRYABLE, $classifier->classify($ex));
    }

    public function testClassificaNonRetryable(): void
    {
        $classifier = new MigrationFailureClassifier();
        $ex = new Exception('SQLSTATE[42000]: Syntax error or access violation: 1064 You have an error in your SQL syntax', 1064);

        $this->assertSame(MigrationFailureClassifier::NON_RETRYABLE, $classifier->classify($ex));
    }
}
