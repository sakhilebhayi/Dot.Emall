<?php

namespace Tests\Unit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserOperatorFlagTest extends TestCase
{
    use RefreshDatabase;

    public function test_is_platform_operator_defaults_to_false(): void
    {
        // ->fresh() is required here, not stylistic: the DB-level default
        // is applied server-side and never populates the in-memory model
        // returned by create() (Eloquent's insert only refreshes the
        // auto-incrementing key), so asserting directly against the
        // just-created instance reads an unset PHP attribute (null), not
        // the real column value.
        $user = User::factory()->create()->fresh();

        $this->assertFalse($user->is_platform_operator);
    }

    public function test_is_platform_operator_is_not_mass_assignable(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'operator-probe@example.com',
            'password' => bcrypt('password'),
            'is_platform_operator' => true,
        ]);

        $this->assertFalse($user->fresh()->is_platform_operator);
    }
}
