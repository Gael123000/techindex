<?php

namespace App\Http\Controllers;

use App\Enums\ClientPermission;
use App\Services\Audit\AuditLogger;
use App\Services\Export\ExportService;
use App\Services\Spreadsheets\SpreadsheetWriter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Export global (clients ou équipements) limité aux clients pour lesquels
 * l'utilisateur dispose du droit d'exportation.
 */
class ExportController extends Controller
{
    public function __invoke(Request $request, string $dataset, string $format, ExportService $exports, SpreadsheetWriter $writer, AuditLogger $audit): StreamedResponse
    {
        $clientIds = $request->user()->clientIdsWith(ClientPermission::Export);

        abort_if($clientIds === [], 403, 'Vous n\'avez le droit d\'export sur aucun client.');

        $audit->log('export.generated', metadata: [
            'données' => $dataset === 'clients' ? 'Clients' : 'Équipements',
            'format' => $format,
            'lignes' => $exports->count($dataset, $clientIds),
            'périmètre' => $clientIds === null ? 'tous les clients' : count($clientIds).' client(s)',
        ]);

        return $writer->download(
            'techindex-'.$dataset.'-'.now()->format('Ymd-His'),
            $format,
            $exports->headings($dataset),
            $exports->rows($dataset, $clientIds),
        );
    }
}
