<?php

namespace App\Services\Updates;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Lance techindex-plesk.sh en arrière-plan, détaché du planificateur : le
 * script déplace le dossier de l'application pendant la mise à jour et doit
 * survivre à la fin du processus qui l'a démarré.
 */
class UpdateLauncher
{
    public function launch(string $script, string $version, ?int $requestedBy = null): void
    {
        $setsid = (new ExecutableFinder)->find('setsid') !== null ? 'setsid ' : '';

        Process::fromShellCommandline($setsid.'nohup bash "${:SCRIPT}" update --version "${:VERSION}" > /dev/null 2>&1 &', dirname($script))
            ->setTimeout(30)
            ->mustRun(null, [
                'SCRIPT' => $script,
                'VERSION' => $version,
                'TECHINDEX_UPDATE_SOURCE' => 'interface',
                'TECHINDEX_UPDATE_REQUESTED_BY' => (string) ($requestedBy ?? ''),
            ]);
    }
}
