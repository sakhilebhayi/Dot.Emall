<?php

namespace App\Http\Controllers\Operator\Concerns;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Runs an operator decision and answers it the same way everywhere: success is a toast and back; a refused decision (no reason, wrong
 * state) goes back with the sentence under the form that asked, named by `$bag`, and the typed text kept. Nothing is half done: the
 * decision service saves the change and its audit entry in one transaction.
 */
trait AnswersDecisions
{
    protected function attempt(string $bag, Closure $decision, string $success, ?string $to = null): RedirectResponse
    {
        try {
            $decision();
        } catch (ValidationException $e) {
            return back()->withInput()->withErrors($e->errors())->withErrors($e->errors(), $bag)->with('open_form', $bag);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['reason' => $e->getMessage()])->withErrors(['reason' => $e->getMessage()], $bag)->with('open_form', $bag);
        }

        return ($to !== null ? redirect($to) : back())->with('status', $success);
    }
}
