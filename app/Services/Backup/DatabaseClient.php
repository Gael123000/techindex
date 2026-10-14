<?php

namespace App\Services\Backup;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Exécution des outils mariadb-dump / mariadb pour la connexion configurée.
 * Les identifiants passent par un fichier d'options temporaire (0600) et
 * n'apparaissent jamais dans la ligne de commande ni dans les journaux.
 */
class DatabaseClient
{
    public function dump(string $targetFile, ?string $database = null): void
    {
        $this->withOptionsFile(function (string $options) use ($targetFile, $database): void {
            $process = new Process([
                $this->binary('mariadb-dump'),
                '--defaults-extra-file='.$options,
                '--single-transaction',
                '--quick',
                '--routines',
                '--triggers',
                '--hex-blob',
                '--no-tablespaces',
                '--default-character-set=utf8mb4',
                '--result-file='.$targetFile,
                $database ?? $this->config('database'),
            ]);
            $process->setTimeout(3600)->mustRun();
        });
    }

    public function import(string $sqlFile, ?string $database = null): void
    {
        $this->withOptionsFile(function (string $options) use ($sqlFile, $database): void {
            $input = fopen($sqlFile, 'rb');

            if ($input === false) {
                throw new RuntimeException('Fichier SQL illisible.');
            }

            $process = new Process([
                $this->binary('mariadb'),
                '--defaults-extra-file='.$options,
                '--default-character-set=utf8mb4',
                $database ?? $this->config('database'),
            ]);
            $process->setInput($input)->setTimeout(3600)->mustRun();
        });
    }

    public function statement(string $sql): void
    {
        $this->withOptionsFile(function (string $options) use ($sql): void {
            (new Process([$this->binary('mariadb'), '--defaults-extra-file='.$options, '-e', $sql]))->setTimeout(600)->mustRun();
        });
    }

    private function withOptionsFile(callable $callback): void
    {
        $file = tempnam(sys_get_temp_dir(), 'tix-db-');

        if ($file === false) {
            throw new RuntimeException('Impossible de créer un fichier temporaire.');
        }

        chmod($file, 0600);
        $quote = fn (string $value): string => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';

        file_put_contents($file, implode("\n", [
            '[client]',
            'host='.$quote($this->config('host')),
            'port='.(int) $this->config('port'),
            'user='.$quote($this->config('username')),
            'password='.$quote((string) $this->config('password')),
            '',
        ]));

        try {
            $callback($file);
        } finally {
            @unlink($file);
        }
    }

    private function config(string $key): string
    {
        return (string) config('database.connections.'.config('database.default').'.'.$key);
    }

    private function binary(string $name): string
    {
        return (string) (config('techindex.backups.binaries.'.str_replace('-', '_', $name)) ?: $name);
    }
}
