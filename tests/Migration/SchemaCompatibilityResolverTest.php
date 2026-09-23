<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Migration;

use Argws\LaravelUpdater\Migration\MigrationFailureClassifier;
use Argws\LaravelUpdater\Migration\SchemaCompatibilityResolver;
use Illuminate\Database\DatabaseManager;
use PHPUnit\Framework\TestCase;

final class SchemaCompatibilityResolverTest extends TestCase
{
    public function testClassifierRecognizesActiveForeignKeyGuard(): void
    {
        $classifier = new MigrationFailureClassifier();

        $exception = new \RuntimeException(
            'Alteração depende de FK ativa (fk_alteracao_estoques_filial): alteracao_estoques.filial_id.'
        );

        self::assertSame(
            MigrationFailureClassifier::SCHEMA_COMPATIBILITY_WARNING,
            $classifier->classify($exception)
        );
    }

    public function testInfersNullableIntentFromMysqlAlterFailure(): void
    {
        $resolver = new SchemaCompatibilityResolver(
            $this->createMock(DatabaseManager::class),
            sys_get_temp_dir() . '/argws-updater-schema-repairs'
        );

        $method = new \ReflectionMethod($resolver, 'inferContext');
        $method->setAccessible(true);

        $context = $method->invoke(
            $resolver,
            'SQLSTATE[HY000]: General error: 3780 Referencing column filial_id and referenced column id are incompatible. '
            . '(Connection: mysql, SQL: alter table \x60apontamentos\x60 modify column \x60filial_id\x60 INT UNSIGNED NULL)'
        );

        self::assertSame(['apontamentos', 'filial_id', true, 'sql_error'], $context);
    }

    public function testInfersNullableIntentFromActiveForeignKeyGuard(): void
    {
        $resolver = new SchemaCompatibilityResolver(
            $this->createMock(DatabaseManager::class),
            sys_get_temp_dir() . '/argws-updater-schema-repairs'
        );

        $method = new \ReflectionMethod($resolver, 'inferContext');
        $method->setAccessible(true);

        $context = $method->invoke(
            $resolver,
            'Alteração depende de FK ativa (fk_alteracao_estoques_filial): alteracao_estoques.filial_id.'
        );

        self::assertSame(['alteracao_estoques', 'filial_id', true, 'active_fk_guard'], $context);
    }
}
