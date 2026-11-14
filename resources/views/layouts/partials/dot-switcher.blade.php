{{--
  The shared platform switcher (R-51, <x-dot.switcher>) for a signed-in person. Variables: $user. Signed-in headers only: a guest has no company, so nothing here
  ever asks InfoDot on a public page. The list comes from InfoDot server side for the person's current company (the one the entitlement check uses); a personal
  workspace shows the switcher's own personal state. Every tile leaves through InfoDot's /go, which decides.
--}}
<x-dot.switcher platform="emall" :company="\App\Support\Navigation::switcherCompany($user)" :company-name="$user->currentTeam?->name" logos="/dot-ui/img/platforms" />
