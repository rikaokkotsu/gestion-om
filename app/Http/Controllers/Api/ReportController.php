<?php

namespace App\Http\Controllers\Api;

use App\Exports\AnomaliesExport;
use App\Exports\MissionOrdersExport;
use App\Http\Controllers\Controller;
use App\Models\MissionOrder;
use App\Models\User;
use App\Reports\Dashboard;
use App\Reports\MissionOrderReport;
use App\Reports\Pdf;
use App\Reports\SummaryReport;
use App\Search\AnomalySearch;
use App\Search\MissionOrderSearch;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Tableau de bord, exports et rapports (§ 14.3 et 14.4). Tous partent des
 * critères de recherche des OM ; chaque export et chaque rapport édité est
 * inscrit au journal d'audit.
 */
class ReportController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        $filters = MissionOrderSearch::validate($request);

        return response()->json([
            'data' => Dashboard::figures(MissionOrderSearch::query($filters)),
            'criteria' => MissionOrderSearch::describe($filters),
        ]);
    }

    public function summary(Request $request): Response
    {
        $filters = MissionOrderSearch::validate($request);
        $criteria = MissionOrderSearch::describe($filters);
        $actor = $this->actor($request);
        $data = SummaryReport::data(MissionOrderSearch::query($filters), $criteria, $actor);

        $pdf = Pdf::render('reports.summary', $data, 'Récapitulatif des OM', 'landscape');
        Audit::record('report.summary', null, [], ['criteria' => self::criteria($criteria), 'orders' => $data['figures']['orders']['total']], $actor);

        return $this->file($pdf, 'application/pdf', 'recapitulatif-om-'.now()->format('Y-m-d').'.pdf');
    }

    public function missionOrder(Request $request, MissionOrder $missionOrder): Response
    {
        $actor = $this->actor($request);
        $pdf = Pdf::render('reports.mission-order', MissionOrderReport::data($missionOrder, $actor), 'Rapport de contrôle de l\'OM '.$missionOrder->auditLabel());

        Audit::record('report.mission_order', $missionOrder, [], [], $actor);

        return $this->file($pdf, 'application/pdf', 'rapport-controle-'.self::slug($missionOrder).'.pdf');
    }

    public function exportMissionOrders(Request $request): BinaryFileResponse
    {
        $filters = MissionOrderSearch::validate($request);
        $criteria = MissionOrderSearch::describe($filters);
        $actor = $this->actor($request);

        $path = (string) tempnam(sys_get_temp_dir(), 'om');
        $count = MissionOrdersExport::write(MissionOrderSearch::query($filters), self::header('OM', $criteria, $actor), $path);

        Audit::record('export.mission_orders', null, [], ['criteria' => self::criteria($criteria), 'orders' => $count], $actor);

        return response()->download($path, 'om-'.now()->format('Y-m-d').'.xlsx')->deleteFileAfterSend();
    }

    public function exportAnomalies(Request $request, AnomalySearch $search, AnomaliesExport $export): BinaryFileResponse
    {
        $filters = $search->validate($request);
        $criteria = $search->describe($filters);
        $actor = $this->actor($request);

        $path = (string) tempnam(sys_get_temp_dir(), 'anomalies');
        $count = $export->write(AnomalySearch::query($filters), self::header('anomalies', $criteria, $actor), $path);

        Audit::record('export.anomalies', null, [], ['criteria' => self::criteria($criteria), 'anomalies' => $count], $actor);

        return response()->download($path, 'anomalies-'.now()->format('Y-m-d').'.xlsx')->deleteFileAfterSend();
    }

    /**
     * @param  list<string>  $criteria
     * @return list<string>
     */
    private static function header(string $subject, array $criteria, ?User $actor): array
    {
        return [
            "Export des {$subject} du ".now()->format('d/m/Y à H:i').($actor ? " par {$actor->name}" : ''),
            $criteria === [] ? 'Critères : aucun' : 'Critères :',
            ...array_map(fn (string $line) => "  {$line}", $criteria),
        ];
    }

    /**
     * @param  list<string>  $criteria
     */
    private static function criteria(array $criteria): string
    {
        return $criteria === [] ? 'aucun' : implode(' ; ', $criteria);
    }

    private static function slug(MissionOrder $order): string
    {
        return $order->reference === null ? "om-{$order->id}" : Str::slug($order->reference);
    }

    private function file(string $content, string $type, string $name): Response
    {
        return response($content, 200, [
            'Content-Type' => $type,
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
        ]);
    }

    private function actor(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }
}
