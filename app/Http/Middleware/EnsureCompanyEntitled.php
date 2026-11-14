<?php

namespace App\Http\Middleware;

use App\Models\Team;
use App\Models\User;
use App\Services\InfoDot\EntitlementClient;
use App\Support\EntitlementRefusal;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The signed-in area honours the company's entitlement (R-50).
 *
 * InfoDot decides whether a company holds Dot.Emall. A person who is already signed in must stop working when that
 * ends, so each request in the signed-in area asks (through a ten-minute cache) whether the company whose store is
 * being opened still holds the platform. A clear "no" answers with the plain refusal page and HTTP 403: nothing is
 * read from or written to the book. Anything else, including every kind of failure, lets the request through
 * exactly as before.
 *
 * It sits on the signed-in route group (routes/web.php) and on Jetstream's own pages (config/jetstream.php), and is
 * a Livewire persistent middleware, so component updates from those pages are checked too. It is not on sign-in,
 * sign-out, the handoff receiver, health checks, the API, or the public form: a respondent filling in a published
 * form is never asked about the company behind it.
 */
class EnsureCompanyEntitled
{
    /**
     * Routes inside the checked groups that keep working for a person whose company is refused: switching company, the
     * person's own account and data rights, the platform operators' own desk (staff of this platform, not a company's
     * tenants), and everything about an order that already exists, on both sides. Money has changed hands or is owed on
     * those, so a refusal must never strand a payment, a delivery, a return or a refund half way.
     */
    private const EXEMPT = [
        'current-team.update', // switching to another company
        'profile.show',        // the person's own account
        'terms.show', 'policy.show', 'team-invitations.accept',
        'account.privacy', 'account.privacy.*', // seeing and exporting one's own data
        'operator.*',
        'orders.*', 'order-items.*', 'account.returns', 'account.returns.*', // a buyer's existing orders: view, pay, receive, return
        'seller.orders.*', 'seller.order-items.*', 'seller.shipments.*', 'seller.returns', 'seller.returns.*', // a seller's existing orders: pack, ship, deliver, returns
    ];

    /** Session key: redeem's `entitled` for the person's personal workspace, remembered at sign-in (absent: not set). */
    public const PERSONAL = 'infodot.personal_entitled';

    public function __construct(private readonly EntitlementClient $entitlements) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->entitlements->enabled() || $request->routeIs(...self::EXEMPT)) {
            return $next($request);
        }

        $user = $request->user() ?? Auth::guard('web')->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $team = $this->company($request, $user);

        // A personal workspace, or a team InfoDot does not know: the read endpoint is never asked. What InfoDot said
        // about the person's personal workspace at sign-in decides: exactly false is a refusal (individuals hold
        // the free core only); true, or nothing remembered, carries on.
        if (! $team) {
            return $next($request);
        }

        if ($team->personal_team || blank($team->infodot_team_uuid)) {
            if (! $request->hasSession() || $request->session()->get(self::PERSONAL) !== false) {
                return $next($request);
            }

            return $this->refuse($request, fn () => EntitlementRefusal::response(
                'company_required',
                null,
                $user->allTeams()->reject(fn ($other) => $other->id === $team->id || $other->personal_team || blank($other->infodot_team_uuid)),
            ));
        }

        $answer = $this->entitlements->forCompany((string) $team->infodot_team_uuid);

        if ($answer === null || $answer['entitled'] === true) {
            return $next($request);
        }

        return $this->refuse($request, fn () => EntitlementRefusal::response(
            $answer['status'],
            $team->name,
            // Someone whose personal workspace InfoDot refused is not offered it (or any team InfoDot does not know) as a way round.
            $user->allTeams()->reject(fn ($other) => $other->id === $team->id
                || ($request->hasSession() && $request->session()->get(self::PERSONAL) === false && ($other->personal_team || blank($other->infodot_team_uuid)))),
        ));
    }

    /**
     * Livewire throws away any non-redirect response a persistent middleware returns, so a refusal page would let a
     * component update run. On a Livewire update the refusal must abort: nothing runs and no component comes back.
     */
    private function refuse(Request $request, Closure $page): Response
    {
        if ($request->headers->has('X-Livewire') || request()->headers->has('X-Livewire')) {
            abort(403);
        }

        return $page();
    }

    /** The company whose book this request opens: the one in the address when the person belongs to it, else their current one. */
    private function company(Request $request, User $user): ?Team
    {
        $named = $request->route('team');

        if (is_scalar($named)) {
            $named = $user->allTeams()->firstWhere('id', (int) $named);
        }

        if ($named instanceof Team && $user->belongsToTeam($named)) {
            return $named;
        }

        $current = $user->currentTeam;

        return $current instanceof Team ? $current : null;
    }
}
