<?php

namespace Tests\Feature\Audit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SignedInSmoke;
use Tests\TestCase;

/**
 * Every page answers what it should for the person meant to see it. The same check runs against cached routes, config
 * and events with `sh tests/Support/signed-in-smoke.sh` (production boots that way, and CI runs it); if this suite is
 * itself run after `route:cache`, it exercises the cached routes too.
 */
class SignedInSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_page_answers_what_it_should_for_the_right_person(): void
    {
        $smoke = new SignedInSmoke;
        $people = $smoke->seed();

        try {
            $this->openEveryPage($smoke, $people);
        } finally {
            $smoke->forget();
        }
    }

    /** @param array<string, User> $people */
    private function openEveryPage(SignedInSmoke $smoke, array $people): void
    {
        foreach ($smoke->pages() as $page) {
            // A new browser per page: nothing left over from the previous person in the (array) session.
            $this->app['session.store']->flush();
            $this->app['auth']->forgetGuards();

            $browser = $page['who'] === 'guest' ? $this : $this->actingAs($people[$page['who']]);
            $status = $browser->get($page['uri'])->getStatusCode();

            $this->assertSame($page['expect'] ?? 200, $status, "{$page['who']} opening {$page['uri']}");
        }
    }

    public function test_no_authenticated_page_is_left_out_of_the_smoke_list(): void
    {
        $smoke = new SignedInSmoke;
        $smoke->seed();

        $this->assertSame([], $smoke->uncoveredRoutes(), 'Open these in SignedInSmoke::pages() or explain them in SignedInSmoke::SKIPPED.');
    }
}
