<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Services\Operator\NeedsAPerson;
use App\Services\Operator\OperatorAudit;
use Illuminate\View\View;

/** The operator's first page: what is waiting for a person, longest wait first, and what was last decided. */
class HomeController extends Controller
{
    public function __invoke(NeedsAPerson $needs, OperatorAudit $audit): View
    {
        $items = $needs->sorted();

        return view('operator.home', [
            'waiting' => array_values(array_filter($items, fn ($i) => $i['state'] === 'waiting')),
            'unknown' => array_values(array_filter($items, fn ($i) => $i['state'] === 'unknown')),
            'clear' => array_values(array_filter($items, fn ($i) => $i['state'] === 'clear')),
            'recent' => $audit->query()->limit(6)->get(),
        ]);
    }
}
