<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Support;

use RuntimeException;

/**
 * Compressão/descompressão GZIP orientada a stream.
 *
 * Nunca carrega o arquivo inteiro em memória. O consumo adicional de RAM
 * permanece limitado ao tamanho do buffer, independentemente do tamanho
 * do dump de banco.
 */
final class GzipStream
{
    private const CHUNK_SIZE = 1024 * 1024;

    public static function compressFile(string $source, string $target, int $level = 9): void
    {
        if (!is_file($source) || !is_readable($source)) {
            throw new RuntimeException('Arquivo de origem inexistente ou sem permissão de leitura para compactação.');
        }

        $level = max(0, min(9, $level));
        $tmp = $target . '.tmp-' . bin2hex(random_bytes(4));
        $input = @fopen($source, 'rb');
        if (!is_resource($input)) {
            throw new RuntimeException('Falha ao abrir arquivo de origem para compactação.');
        }

        $output = @gzopen($tmp, 'wb' . $level);
        if ($output === false) {
            @fclose($input);
            throw new RuntimeException('Falha ao criar arquivo GZIP temporário.');
        }

        $success = false;

        try {
            while (!feof($input)) {
                $chunk = fread($input, self::CHUNK_SIZE);
                if ($chunk === false) {
                    throw new RuntimeException('Falha ao ler arquivo durante compactação GZIP.');
                }

                if ($chunk === '') {
                    continue;
                }

                if (gzwrite($output, $chunk) === false) {
                    throw new RuntimeException('Falha ao gravar arquivo durante compactação GZIP.');
                }
            }

            $success = true;
        } finally {
            @fclose($input);
            @gzclose($output);

            if (!$success) {
                @unlink($tmp);
            }
        }

        if (!is_file($tmp)) {
            throw new RuntimeException('Arquivo GZIP temporário não foi gerado.');
        }

        @unlink($target);
        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            throw new RuntimeException('Falha ao publicar arquivo GZIP final.');
        }
    }

    public static function decompressFile(string $source, string $target): void
    {
        if (!is_file($source) || !is_readable($source)) {
            throw new RuntimeException('Arquivo GZIP inexistente ou sem permissão de leitura.');
        }

        $tmp = $target . '.tmp-' . bin2hex(random_bytes(4));
        $input = @gzopen($source, 'rb');
        if ($input === false) {
            throw new RuntimeException('Falha ao abrir arquivo GZIP para descompactação.');
        }

        $output = @fopen($tmp, 'wb');
        if (!is_resource($output)) {
            @gzclose($input);
            throw new RuntimeException('Falha ao criar arquivo temporário para descompactação.');
        }

        $success = false;

        try {
            while (!gzeof($input)) {
                $chunk = gzread($input, self::CHUNK_SIZE);
                if ($chunk === false) {
                    throw new RuntimeException('Falha ao ler arquivo durante descompactação GZIP.');
                }

                if ($chunk === '') {
                    continue;
                }

                $offset = 0;
                $length = strlen($chunk);
                while ($offset < $length) {
                    $written = fwrite($output, substr($chunk, $offset));
                    if ($written === false || $written === 0) {
                        throw new RuntimeException('Falha ao gravar arquivo durante descompactação GZIP.');
                    }
                    $offset += $written;
                }
            }

            $success = true;
        } finally {
            @gzclose($input);
            @fclose($output);

            if (!$success) {
                @unlink($tmp);
            }
        }

        if (!is_file($tmp)) {
            throw new RuntimeException('Arquivo temporário descompactado não foi gerado.');
        }

        @unlink($target);
        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            throw new RuntimeException('Falha ao publicar arquivo descompactado.');
        }
    }
}
