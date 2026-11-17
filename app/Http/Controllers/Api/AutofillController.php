<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\AutofillSession;
use App\Services\Automation\AutofillService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AutofillController extends Controller
{
    public function start(Request $request, Application $application, AutofillService $service): JsonResponse
    {
        abort_unless($application->user_id === $request->user()->id, 404);
        $result = $service->start($application->load('listing', 'user.profile'));

        return response()->json($this->present($result['session']), 202);
    }

    public function show(Request $request, AutofillSession $session): JsonResponse
    {
        abort_unless($session->application->user_id === $request->user()->id, 404);

        return response()->json($this->present($session));
    }

    /** Explicit approval by the user: the only way the script is allowed to click submit. */
    public function approve(Request $request, AutofillSession $session, AutofillService $service): JsonResponse
    {
        abort_unless($session->application->user_id === $request->user()->id, 404);
        $request->validate(['confirm' => ['accepted']]);

        return response()->json($this->present($service->approve($session)));
    }

    public function cancel(Request $request, AutofillSession $session, AutofillService $service): JsonResponse
    {
        abort_unless($session->application->user_id === $request->user()->id, 404);

        return response()->json($this->present($service->cancel($session)));
    }

    // ---- Endpoints used by automation/autofill.mjs (per-session token, no user session) ----

    public function poll(Request $request, AutofillSession $session, AutofillService $service): JsonResponse
    {
        abort_unless($service->verifyToken($session, $request->header('X-Autofill-Token')), 403);

        return response()->json(['status' => $session->status]);
    }

    public function report(Request $request, AutofillSession $session, AutofillService $service): JsonResponse
    {
        abort_unless($service->verifyToken($session, $request->header('X-Autofill-Token')), 403);
        if (in_array($session->status, ['submitted', 'cancelled', 'failed'], true)) {
            return response()->json(['status' => $session->status]);
        }
        $data = $request->validate([
            'status' => ['required', 'string', 'max:30'],
            'fields_filled' => ['nullable', 'array'], 'fields_filled.*' => ['string', 'max:200'],
            'fields_skipped' => ['nullable', 'array'], 'fields_skipped.*' => ['string', 'max:300'],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);
        // The script may only report "submitted" after the user approved or submitted it themselves.
        $session = $service->report($session->load('application'), $data);

        return response()->json(['status' => $session->status]);
    }

    private function present(AutofillSession $s): array
    {
        return $s->only(['id', 'application_id', 'status', 'adapter', 'target_url', 'fields_filled', 'fields_skipped', 'message', 'approved_at', 'submitted_at', 'created_at', 'updated_at']);
    }
}
