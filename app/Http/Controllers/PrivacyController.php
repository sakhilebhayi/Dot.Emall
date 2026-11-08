<?php

namespace App\Http\Controllers;

use App\Actions\Jetstream\DeleteUser;
use App\Services\Account\DataExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Your data: see what is kept, download it as JSON, and close the account (refused while orders, returns, refunds or held earnings are open; otherwise the person is anonymised and the shop's records stay). */
class PrivacyController extends Controller
{
    public function show(Request $request, DeleteUser $deleter): View
    {
        return view('account.privacy', ['blockers' => $deleter->blockers($request->user())]);
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
