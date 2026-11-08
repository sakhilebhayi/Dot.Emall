<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Services\Operator\PlatformHealth;
use Illuminate\View\View;

/** Platform health: scheduled tasks and the checks that need a server, from records, with an honest "unknown" where there is none. */
class HealthController extends Controller
{
    public function __invoke(PlatformHealth $health): View
    {
        $tasks = $health->scheduledTasks();
        $checks = $health->checks();

        return view('operator.health', [
            'tasks' => $tasks,
            'checks' => $checks,
            'attention' => collect($tasks)->concat($checks)->where('state', 'attention')->count(),
            'unknown' => collect($tasks)->concat($checks)->where('state', 'unknown')->count(),
        ]);
    }
}
