<?php

namespace App\Http\Controllers\OQLike;

use App\Http\Controllers\Controller;
use App\Models\Issue;
use App\Models\IssueAcknowledgement;
use App\Models\IssueObjectAcknowledgement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class IssueAcknowledgementController extends Controller
{
    public function acknowledgeIssue(Request $request, Issue $issue): RedirectResponse
    {
        if (! $this->ackTableExists()) {
            return redirect()->back()->with('status', 'Acquittement indisponible: migration manquante (issue_acknowledgements). Lancez `php artisan migrate --force`.');
        }

        $issue->loadMissing('scan.connection', 'samples');

        $connectionId = (int) ($issue->scan?->connection_id ?? 0);
        $className = $this->resolveIssueClass($issue);

        if ($connectionId <= 0 || $className === null) {
            return redirect()->back()->with('status', 'Acquittement impossible: classe/connexion introuvable pour cette anomalie.');
        }

        IssueAcknowledgement::query()->updateOrCreate(
            [
                'connection_id' => $connectionId,
                'itop_class' => $className,
                'issue_code' => (string) $issue->code,
            ],
            [
                'domain' => (string) $issue->domain,
                'title' => (string) $issue->title,
                'note' => is_string($request->input('note')) ? trim((string) $request->input('note')) : null,
            ]
        );

        return redirect()->back()->with('status', sprintf(
            'Acquittement actif: %s / %s (%s).',
            $className,
            $issue->code,
            $issue->scan?->connection?->name ?? 'connexion'
        ));
    }

    public function deacknowledgeIssue(Issue $issue): RedirectResponse
    {
        if (! $this->ackTableExists()) {
            return redirect()->back()->with('status', 'Desacquittement indisponible: migration manquante (issue_acknowledgements).');
        }

        $issue->loadMissing('scan', 'samples');

        $connectionId = (int) ($issue->scan?->connection_id ?? 0);
        $className = $this->resolveIssueClass($issue);

        if ($connectionId <= 0 || $className === null) {
            return redirect()->back()->with('status', 'Desacquittement impossible: classe/connexion introuvable pour cette anomalie.');
        }

        IssueAcknowledgement::query()
            ->where('connection_id', $connectionId)
            ->where('itop_class', $className)
            ->where('issue_code', (string) $issue->code)
            ->delete();

        return redirect()->back()->with('status', sprintf('Acquittement retire: %s / %s.', $className, $issue->code));
    }

    public function destroy(IssueAcknowledgement $acknowledgement): RedirectResponse
    {
        if (! $this->ackTableExists()) {
            return redirect()->back()->with('status', 'Desacquittement indisponible: migration manquante (issue_acknowledgements).');
        }

        $className = (string) $acknowledgement->itop_class;
        $issueCode = (string) $acknowledgement->issue_code;
        $acknowledgement->delete();

        return redirect()->back()->with('status', sprintf('Acquittement retire: %s / %s.', $className, $issueCode));
    }

    public function acknowledgeObject(Request $request, Issue $issue): RedirectResponse|JsonResponse
    {
        if (! $this->objectAckTableExists()) {
            return $this->respondBackOrJson(
                $request,
                'Acquittement objet indisponible: migration manquante (issue_object_acknowledgements). Lancez `php artisan migrate --force`.',
                422,
                false
            );
        }

        $validated = $request->validate([
            'itop_class' => ['required', 'string', 'max:255'],
            'itop_id' => ['required', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'link' => ['nullable', 'string', 'max:2048'],
            'note' => ['nullable', 'string'],
        ]);

        $issue->loadMissing('scan.connection');
        $connectionId = (int) ($issue->scan?->connection_id ?? 0);

        if ($connectionId <= 0) {
            return $this->respondBackOrJson(
                $request,
                'Acquittement objet impossible: connexion introuvable.',
                422,
                false
            );
        }

        $itopClass = trim((string) $validated['itop_class']);
        $itopId = trim((string) $validated['itop_id']);

        if ($itopClass === '' || $itopId === '') {
            return $this->respondBackOrJson(
                $request,
                'Acquittement objet impossible: classe/id iTop manquants.',
                422,
                false
            );
        }

        IssueObjectAcknowledgement::query()->updateOrCreate(
            [
                'connection_id' => $connectionId,
                'itop_class' => $itopClass,
                'issue_code' => (string) $issue->code,
                'itop_id' => $itopId,
            ],
            [
                'domain' => (string) $issue->domain,
                'title' => (string) $issue->title,
                'object_name' => is_string($validated['name'] ?? null) ? trim((string) $validated['name']) : null,
                'object_link' => is_string($validated['link'] ?? null) ? trim((string) $validated['link']) : null,
                'note' => is_string($validated['note'] ?? null) ? trim((string) $validated['note']) : null,
            ]
        );

        return $this->respondBackOrJson(
            $request,
            sprintf('Acquittement objet actif: %s#%s (%s).', $itopClass, $itopId, (string) $issue->code),
            200,
            true
        );
    }

    public function deacknowledgeObject(Request $request, Issue $issue): RedirectResponse|JsonResponse
    {
        if (! $this->objectAckTableExists()) {
            return $this->respondBackOrJson(
                $request,
                'Desacquittement objet indisponible: migration manquante (issue_object_acknowledgements).',
                422,
                false
            );
        }

        $validated = $request->validate([
            'itop_class' => ['required', 'string', 'max:255'],
            'itop_id' => ['required', 'string', 'max:255'],
        ]);

        $issue->loadMissing('scan.connection');
        $connectionId = (int) ($issue->scan?->connection_id ?? 0);

        if ($connectionId <= 0) {
            return $this->respondBackOrJson(
                $request,
                'Desacquittement objet impossible: connexion introuvable.',
                422,
                false
            );
        }

        $itopClass = trim((string) $validated['itop_class']);
        $itopId = trim((string) $validated['itop_id']);

        IssueObjectAcknowledgement::query()
            ->where('connection_id', $connectionId)
            ->where('issue_code', (string) $issue->code)
            ->where('itop_class', $itopClass)
            ->where('itop_id', $itopId)
            ->delete();

        return $this->respondBackOrJson(
            $request,
            sprintf('Acquittement objet retire: %s#%s (%s).', $itopClass, $itopId, (string) $issue->code),
            200,
            true
        );
    }

    private function resolveIssueClass(Issue $issue): ?string
    {
        $className = data_get($issue->meta_json, 'class');

        if (is_string($className) && $className !== '') {
            return $className;
        }

        $sampleClass = $issue->samples->first()?->itop_class;

        if (is_string($sampleClass) && $sampleClass !== '') {
            return $sampleClass;
        }

        return null;
    }

    private function ackTableExists(): bool
    {
        return Schema::hasTable('issue_acknowledgements');
    }

    private function objectAckTableExists(): bool
    {
        return Schema::hasTable('issue_object_acknowledgements');
    }

    private function respondBackOrJson(Request $request, string $message, int $statusCode = 200, bool $ok = true): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json([
                'ok' => $ok,
                'status' => $message,
            ], $statusCode);
        }

        return redirect()->back()->with('status', $message);
    }
}

