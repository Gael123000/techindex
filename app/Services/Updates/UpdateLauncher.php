<?php

namespace App\Services\Updates;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Lance techindex-plesk.sh en arrière-plan, détaché du planificateur : le
 * script déplace le dossier de l'application pendant la mise à jour et doit
 * survivre à la fin du processus qui l'a démarré.
 *
 * Sa sortie est copiée dans $log : une erreur survenue avant que le script
 * n'ouvre son propre journal (bash ou PHP introuvable...) reste lisible.
 */
class UpdateLauncher
{
    public function launch(string $script, string $version, ?int $requestedBy = null, ?string $log = null): void
    {
        $setsid = (new ExecutableFinder)->find('setsid') !== null ? 'setsid ' : '';
        $output = $log === null ? '/dev/null' : '"${:LOG}"';

        Process::fromShellCommandline($setsid.'nohup bash "${:SCRIPT}" update --version "${:VERSION}" < /dev/null > '.$output.' 2>&1 &', dirname($script))
            ->setTimeout(30)
            ->mustRun(null, [
                'SCRIPT' => $script,
                'VERSION' => $version,
                'LOG' => (string) $log,
                'TECHINDEX_UPDATE_SOURCE' => 'interface',
                'TECHINDEX_UPDATE_REQUESTED_BY' => (string) ($requestedBy ?? ''),
            ]);
    }
}
