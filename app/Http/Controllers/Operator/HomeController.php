<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\OperatorHome;
use Illuminate\View\View;

/** The operator's first page: what is waiting for a person, longest wait first, and what was last decided (R-51: one data class). */
class HomeController extends Controller
{
    public function __invoke(OperatorHome $home): View
    {
        return view('operator.home', $home->data());
    }
}
