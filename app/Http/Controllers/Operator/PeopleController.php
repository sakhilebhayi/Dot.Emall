<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Operator\Concerns\AnswersDecisions;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\Services\Operator\OperatorAccess;
use App\Services\Operator\OperatorAudit;
use App\Services\Operator\SupportLookup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Support lookup and operator access. Searching lists names with masked e-mail addresses; OPENING a person, store or order shows personal
 * data, so each opening writes an access-log entry first (who, what, when, and the purpose chosen).
 */
class PeopleController extends Controller
{
    use AnswersDecisions;

    public function index(Request $request, SupportLookup $lookup, OperatorAudit $audit): View
    {
        $type = in_array($request->query('type'), ['user', 'store', 'order'], true) ? $request->query('type') : 'user';
        $q = trim((string) $request->query('q', ''));
        $purpose = array_key_exists((string) $request->query('purpose'), SupportLookup::PURPOSES) ? (string) $request->query('purpose') : 'support';

        return view('operator.people.index', [
            'type' => $type,
            'q' => $q,
            'purpose' => $purpose,
            'purposes' => SupportLookup::PURPOSES,
            'results' => $q !== '' ? $lookup->search($type, $q) : null,
            'accesses' => \App\Models\OperatorAccessEntry::query()->orderByDesc('id')->limit(8)->get(),
        ]);
    }

    public function user(Request $request, User $user, SupportLookup $lookup, OperatorAudit $audit): View
    {
        $this->opened($request, $audit, 'user', $user->id, $user->name);

        return view('operator.people.user', $lookup->user($user) + ['purposes' => SupportLookup::PURPOSES]);
    }

    public function store(Request $request, Store $store, SupportLookup $lookup, OperatorAudit $audit): View
    {
        $this->opened($request, $audit, 'store', $store->id, $store->name);

        return view('operator.people.store', $lookup->store($store));
    }

    public function order(Request $request, Order $order, SupportLookup $lookup, OperatorAudit $audit): View
    {
        $this->opened($request, $audit, 'order', $order->id, 'Order '.$order->reference());

        return view('operator.people.order', $lookup->order($order));
    }

    public function operators(OperatorAccess $access, OperatorAudit $audit): View
    {
        return view('operator.people.operators', [
            'operators' => $access->operators(),
            'history' => $audit->query('operator.*')->limit(10)->get(),
        ]);
    }

    public function grant(Request $request, OperatorAccess $access): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'reason' => ['nullable', 'string', 'max:500']]);
        $target = User::query()->whereRaw('lower(email) = ?', [mb_strtolower($data['email'])])->first();

        if ($target === null) {
            return back()->withInput()->withErrors(['email' => 'No account has that e-mail address. The person signs in through InfoDot once first.'], 'grant');
        }

        return $this->attempt('grant', fn () => $access->grant($target, $request->user(), $data['reason'] ?? null), $target->name.' is now an operator.');
    }

    public function revoke(Request $request, User $user, OperatorAccess $access): RedirectResponse
    {
        $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return $this->attempt('revoke-'.$user->id, fn () => $access->revoke($user, $request->user(), $request->input('reason')), $user->name.' is no longer an operator.');
    }

    private function opened(Request $request, OperatorAudit $audit, string $type, int $id, string $label): void
    {
        $purpose = (string) $request->query('purpose');
        $audit->recordAccess($request->user(), $type, $id, $label, SupportLookup::PURPOSES[$purpose] ?? null);
    }
}
