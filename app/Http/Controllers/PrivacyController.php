<?php

namespace App\Http\Controllers;

use App\Actions\Jetstream\DeleteUser;
use App\Services\Account\DataExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Your data: see what is kept, download it as JSON, and delete the account under the existing rules (refused while orders or refunds are open). */
class PrivacyController extends Controller
{
    public function show(Request $request, DeleteUser $deleter): View
    {
        return view('account.privacy', ['blocker' => $deleter->blockedReason($request->user())]);
    }

    public function export(Request $request, DataExport $export): JsonResponse
    {
        $data = $export->for($request->user());

        return response()->json($data, 200, [
            'Content-Disposition' => 'attachment; filename="dot-emall-my-data-'.now()->format('Y-m-d').'.json"',
            'Cache-Control' => 'private, no-store',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
