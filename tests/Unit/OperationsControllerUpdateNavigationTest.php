<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Unit;

use PHPUnit\Framework\TestCase;

class OperationsControllerUpdateNavigationTest extends TestCase
{
    public function testUpdateRealPermaneceNaTelaDeAtualizacoes(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/Http/Controllers/OperationsController.php');

        $this->assertIsString($source);
        $this->assertStringContainsString(
            'Atualização #\' . $runId . \' iniciada com sucesso. Acompanhe o progresso nesta tela.',
            $source
        );
        $this->assertStringContainsString(
            "return redirect()->route('updater.section', ['section' => 'updates'])",
            $source
        );
    }

    public function testAprovacaoDeUpdateRealTambemPermaneceNaTelaDeAtualizacoes(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/Http/Controllers/OperationsController.php');

        $this->assertIsString($source);
        $this->assertStringContainsString(
            'Atualização #\' . $runId . \' aprovada e iniciada com sucesso. Acompanhe o progresso nesta tela.',
            $source
        );
    }
}
