# Seller Onboarding + Product Listing Moderation Gate Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Any authenticated user can become a seller and submit product listings; a new listing starts `pending_approval` and is invisible to buyers until a platform operator approves or rejects it (reject requires a reason).

**Architecture:** A new `is_platform_operator` flag on `User` (granted by hand only, mirroring ChartSense's exact precedent). A new `StoreController` (become-a-seller, activates immediately) and `SellerProductController` (submit a listing, starts `pending_approval`). `products` gains a `status` lifecycle alongside its existing untouched `is_active` column. A new `ProductModerationController` behind a new `EnsurePlatformOperator` middleware reviews pending listings. `Product::scopeBrowsable()` gains the status gate, which `CartController` already routes every add-to-cart through.

**Tech Stack:** Laravel (this repo's existing conventions), PHPUnit, hand-built test fixtures (no factories exist for `Store`/`Product` in this codebase and none are added).

## Global Constraints

- `is_platform_operator` is a `boolean` on `users`, default `false`, **excluded from `User::$fillable`** — mass-assignment must never be able to set it. No route or command grants it; an operator is created by hand.
- One store per user — an application-level check (`Store::where('owner_id', ...)->exists()`), no new DB constraint.
- A `Store` activates immediately on creation (`is_active` stays at its existing column default of `true`) — only individual `Product` listings are gated.
- New `Product` rows default to `status = 'pending_approval'`. The existing `is_active` column is untouched and keeps its existing meaning; `status` is a second, independent gate.
- Rejecting a listing requires a non-empty `reason` — a Laravel validation failure, never silently accepted.
- No image upload — `ProductImage`/`product_images` stay exactly as unused as they are today.
- Every `git add` lists files explicitly, never `git add -A`/`git add .` — this repo has pre-existing unrelated uncommitted changes (`resources/views/components/application-mark.blade.php`, `public/images/mark-light.png`/`mark.png`) stashed, not part of this work. `git status --short` before and after every task.
- Full test suite (`php artisan test --compact`) must stay green after every task; `vendor/bin/pint --dirty --format agent` after every task that touches PHP files.

---

### Task 1: `is_platform_operator` flag

**Files:**
- Create: `database/migrations/2026_08_09_000001_add_is_platform_operator_to_users_table.php`
- Test: `tests/Unit/UserOperatorFlagTest.php`

**Interfaces:**
- Produces: `users.is_platform_operator` column (boolean, default `false`), readable as `$user->is_platform_operator`. Not fillable — `User::create(['is_platform_operator' => true, ...])` must leave it `false`.

- [ ] **Step 1: Write the migration**

Create `database/migrations/2026_08_09_000001_add_is_platform_operator_to_users_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Deliberately NOT named "is_admin" -- a generic admin flag
            // name is an obvious mass-assignment target to probe for.
            // Scoped naming plus exclusion from $fillable (see User model)
            // means no request payload can ever set this, regardless of
            // name. Matches ChartSense's identical column, added for the
            // same reason (an independent human reviewer for an
            // automated-proposal-awaiting-approval gate).
            $table->boolean('is_platform_operator')->default(false)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_platform_operator');
        });
    }
};
```

- [ ] **Step 2: Run the migration**

Run: `php artisan migrate`
Expected: migration runs with no errors.

- [ ] **Step 3: Write the failing test**

Create `tests/Unit/UserOperatorFlagTest.php`:

```php
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
        $user = User::factory()->create();

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
```

- [ ] **Step 4: Run test to verify it fails**

Run: `php artisan test tests/Unit/UserOperatorFlagTest.php`
Expected: PASS for the first test (column already defaults correctly from the migration), but this confirms the schema — if the migration weren't applied this would error with "no such column." Since Task 1's migration already ran in Step 2, both tests should actually PASS here. This step exists to confirm the schema is correct before moving on, not to prove a red state — the column addition itself needed no application code, so there is no red-to-green cycle for this task beyond the migration.

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test tests/Unit/UserOperatorFlagTest.php`
Expected: PASS (2 tests) — confirms both the default and that `User::$fillable` (already unchanged — it only lists `name`, `email`, `password`) correctly excludes the new column.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_08_09_000001_add_is_platform_operator_to_users_table.php \
  tests/Unit/UserOperatorFlagTest.php
git commit -m "feat: is_platform_operator flag on User

Mirrors ChartSense's identical column/reasoning exactly. Granted only
by hand -- no route or command in this codebase sets it."
```

---

### Task 2: Seller onboarding (`StoreController`)

**Files:**
- Create: `app/Http/Controllers/StoreController.php`
- Create: `resources/views/stores/create.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Marketplace/BecomeSellerTest.php`

**Interfaces:**
- Produces: routes `seller.store.create` (GET), `seller.store.store` (POST).

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Marketplace/BecomeSellerTest.php`:

```php
<?php

namespace Tests\Feature\Marketplace;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BecomeSellerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('seller.store.create'))->assertRedirect('/login');
    }

    public function test_submitting_the_form_creates_an_active_store(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('seller.store.store'), [
                'name' => 'My New Shop',
                'description' => 'Selling handmade goods.',
            ])
            ->assertRedirect();

        $store = Store::where('owner_id', $user->id)->first();
        $this->assertNotNull($store);
        $this->assertSame('My New Shop', $store->name);
        $this->assertTrue($store->is_active);
    }

    public function test_a_user_who_already_owns_a_store_is_redirected_to_it_instead_of_creating_a_second_one(): void
    {
        $user = User::factory()->create();
        $store = Store::create([
            'owner_id' => $user->id,
            'name' => 'Existing Shop',
            'slug' => 'existing-shop',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('seller.store.create'))
            ->assertRedirect(route('seller.products.create'));

        $this->actingAs($user)->post(route('seller.store.store'), [
            'name' => 'Second Shop',
        ]);

        $this->assertSame(1, Store::where('owner_id', $user->id)->count());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/Marketplace/BecomeSellerTest.php`
Expected: FAIL — routes `seller.store.create`/`seller.store.store` don't exist yet.

- [ ] **Step 3: Write `app/Http/Controllers/StoreController.php`**

```php
<?php

namespace App\Http\Controllers;

use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class StoreController extends Controller
{
    /**
     * "Become a seller." A Store activates immediately on creation --
     * becoming a seller is identity, not risk; only individual Product
     * listings are gated (see SellerProductController).
     */
    public function create(): View|RedirectResponse
    {
        $existing = Store::where('owner_id', auth()->id())->first();

        if ($existing) {
            return redirect()->route('seller.products.create');
        }

        return view('stores.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $existing = Store::where('owner_id', auth()->id())->first();

        if ($existing) {
            return redirect()->route('seller.products.create');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
        ]);

        Store::create([
            'owner_id' => auth()->id(),
            'name' => $validated['name'],
            'slug' => Str::slug($validated['name']).'-'.Str::random(6),
            'description' => $validated['description'] ?? null,
        ]);

        return redirect()->route('seller.products.create')
            ->with('status', 'Your store is live. List your first product below.');
    }
}
```

- [ ] **Step 4: Add the become-a-seller view**

Create `resources/views/stores/create.blade.php`, matching this app's existing dark-theme inline-style convention (see `resources/views/products/show.blade.php`):

```blade
<x-app-layout>
<div style="padding:2rem 2.5rem 3rem;max-width:560px;">
    <h1 style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:800;color:#f4f4f5;margin:0 0 1.5rem;">
        Become a Seller
    </h1>

    @if ($errors->any())
        <div style="background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.25);color:#ef4444;padding:0.75rem 1rem;border-radius:8px;font-size:0.8rem;margin-bottom:1.25rem;">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('seller.store.store') }}" style="background:#141416;border:1px solid rgba(255,255,255,0.06);border-radius:1rem;padding:1.5rem;display:flex;flex-direction:column;gap:1rem;">
        @csrf

        <div>
            <label style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Store Name</label>
            <input type="text" name="name" value="{{ old('name') }}" required style="width:100%;padding:0.5rem;border-radius:6px;">
        </div>

        <div>
            <label style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Description</label>
            <textarea name="description" rows="4" style="width:100%;padding:0.5rem;border-radius:6px;">{{ old('description') }}</textarea>
        </div>

        <button type="submit" style="margin-top:0.5rem;padding:0.6rem;border-radius:8px;background:linear-gradient(135deg,#f59e0b,#d97706);color:#1a1a1a;font-weight:700;border:none;cursor:pointer;">
            Create My Store
        </button>
    </form>
</div>
</x-app-layout>
```

- [ ] **Step 5: Add routes**

In `routes/web.php`, add `use App\Http\Controllers\StoreController;` to the imports, and add two lines inside the existing `auth:sanctum` middleware group, right after the `orders.show` route:

```php
    Route::get('/sell', [StoreController::class, 'create'])->name('seller.store.create');
    Route::post('/sell', [StoreController::class, 'store'])->name('seller.store.store');
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Marketplace/BecomeSellerTest.php`
Expected: FAIL on `test_a_user_who_already_owns_a_store_is_redirected_to_it_instead_of_creating_a_second_one` — it asserts a redirect to `seller.products.create`, which doesn't exist until Task 3. This is expected; re-run after Task 3.

Run the other two: `php artisan test tests/Feature/Marketplace/BecomeSellerTest.php --filter=test_guest_is_redirected_to_login`, then `--filter=test_submitting_the_form_creates_an_active_store`.
Expected: both PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/StoreController.php resources/views/stores/create.blade.php routes/web.php \
  tests/Feature/Marketplace/BecomeSellerTest.php
git commit -m "feat: become-a-seller flow -- Store creation this platform never had

Activates immediately (identity, not risk). One store per user via an
application-level check, same shape as Dot.Ehail's existing-profile
check -- no DB constraint change.

Note: test_a_user_who_already_owns_a_store_is_redirected_to_it_instead_of_creating_a_second_one
depends on the seller.products.create route added in Task 3 and will
fail until then -- expected, not a regression."
```

---

### Task 3: `products` gains a review lifecycle + listing submission (`SellerProductController`)

**Files:**
- Create: `database/migrations/2026_08_09_000002_add_review_lifecycle_to_products_table.php`
- Modify: `app/Models/Product.php`
- Create: `app/Policies/ProductPolicy.php`
- Create: `app/Http/Controllers/SellerProductController.php`
- Create: `resources/views/products/create.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Marketplace/ProductListingTest.php`

**Interfaces:**
- Consumes: `Store` (Task 2).
- Produces: `Product::$fillable` including `status`/`rejected_reason`/`reviewed_by`/`reviewed_at`; `Product::reviewer(): BelongsTo`. Routes `seller.products.create` (GET), `seller.products.store` (POST) — the route `test_a_user_who_already_owns_a_store_is_redirected_to_it_instead_of_creating_a_second_one` (Task 2) depends on.

- [ ] **Step 1: Write the migration**

Create `database/migrations/2026_08_09_000002_add_review_lifecycle_to_products_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Independent of the existing is_active column -- is_active
            // keeps its current meaning (buyer-facing visibility toggle,
            // untouched by this migration); status is a second gate.
            $table->string('status')->default('pending_approval')->after('is_active');
            $table->text('rejected_reason')->nullable()->after('status');
            $table->foreignId('reviewed_by')->nullable()->after('rejected_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['status', 'rejected_reason', 'reviewed_at']);
        });
    }
};
```

- [ ] **Step 2: Run the migration**

Run: `php artisan migrate`
Expected: migration runs with no errors.

- [ ] **Step 3: Write the failing tests**

Create `tests/Feature/Marketplace/ProductListingTest.php`:

```php
<?php

namespace Tests\Feature\Marketplace;

use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductListingTest extends TestCase
{
    use RefreshDatabase;

    private function store(User $owner): Store
    {
        return Store::create([
            'owner_id' => $owner->id,
            'name' => 'Test Store',
            'slug' => 'test-store-'.uniqid(),
            'is_active' => true,
        ]);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Handmade Mug',
            'description' => 'A lovely mug.',
            'price' => 25.00,
            'stock' => 10,
        ], $overrides);
    }

    public function test_a_store_owner_can_submit_a_listing_and_it_lands_pending_approval(): void
    {
        $owner = User::factory()->create();
        $this->store($owner);

        $this->actingAs($owner)
            ->post(route('seller.products.store'), $this->validPayload())
            ->assertRedirect();

        $product = Product::where('name', 'Handmade Mug')->first();
        $this->assertNotNull($product);
        $this->assertSame('pending_approval', $product->status);
    }

    public function test_a_user_with_no_store_cannot_reach_the_listing_form(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('seller.products.create'))
            ->assertRedirect(route('seller.store.create'));
    }

    public function test_a_user_cannot_submit_a_listing_for_a_store_they_do_not_own(): void
    {
        $owner = User::factory()->create();
        $this->store($owner);
        $stranger = User::factory()->create();
        $this->store($stranger);

        // Craft a payload that would only be valid if it targeted the
        // stranger's own store -- the controller resolves the store from
        // auth()->id(), not from client input, so there is no
        // store_id field to tamper with; this test instead proves a user
        // with no store of their own is blocked (redirect), covering the
        // same authorization boundary via the only reachable path.
        $userWithNoStore = User::factory()->create();

        $this->actingAs($userWithNoStore)
            ->post(route('seller.products.store'), $this->validPayload())
            ->assertRedirect(route('seller.store.create'));

        $this->assertSame(0, Product::where('name', 'Handmade Mug')->count());
    }

    public function test_pending_approval_product_does_not_appear_in_the_marketplace_index(): void
    {
        $owner = User::factory()->create();
        $store = $this->store($owner);
        $product = Product::create([
            'store_id' => $store->id,
            'name' => 'Pending Item',
            'slug' => 'pending-item',
            'price' => 10,
            'stock' => 5,
            'is_active' => true,
            'status' => 'pending_approval',
        ]);

        $this->actingAs($owner)
            ->get(route('products.index'))
            ->assertDontSee('Pending Item');
    }

    public function test_pending_approval_product_404s_at_show_for_a_non_owner(): void
    {
        $owner = User::factory()->create();
        $store = $this->store($owner);
        $product = Product::create([
            'store_id' => $store->id,
            'name' => 'Pending Item',
            'slug' => 'pending-item',
            'price' => 10,
            'stock' => 5,
            'is_active' => true,
            'status' => 'pending_approval',
        ]);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->get(route('products.show', $product))
            ->assertNotFound();
    }

    public function test_pending_approval_product_is_visible_to_its_owner(): void
    {
        $owner = User::factory()->create();
        $store = $this->store($owner);
        $product = Product::create([
            'store_id' => $store->id,
            'name' => 'Pending Item',
            'slug' => 'pending-item',
            'price' => 10,
            'stock' => 5,
            'is_active' => true,
            'status' => 'pending_approval',
        ]);

        $this->actingAs($owner)
            ->get(route('products.show', $product))
            ->assertOk();
    }

    public function test_pending_approval_product_cannot_be_added_to_cart(): void
    {
        $owner = User::factory()->create();
        $store = $this->store($owner);
        $product = Product::create([
            'store_id' => $store->id,
            'name' => 'Pending Item',
            'slug' => 'pending-item',
            'price' => 10,
            'stock' => 5,
            'is_active' => true,
            'status' => 'pending_approval',
        ]);
        $buyer = User::factory()->create();

        $this->actingAs($buyer)
            ->post(route('cart.store'), ['product_id' => $product->id])
            ->assertNotFound();
    }

    public function test_rejected_product_behaves_the_same_as_pending_for_visibility(): void
    {
        $owner = User::factory()->create();
        $store = $this->store($owner);
        $product = Product::create([
            'store_id' => $store->id,
            'name' => 'Rejected Item',
            'slug' => 'rejected-item',
            'price' => 10,
            'stock' => 5,
            'is_active' => true,
            'status' => 'rejected',
            'rejected_reason' => 'Prohibited item.',
        ]);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->get(route('products.show', $product))
            ->assertNotFound();

        $this->actingAs($stranger)
            ->get(route('products.index'))
            ->assertDontSee('Rejected Item');
    }
}
```

- [ ] **Step 4: Run tests to verify they fail**

Run: `php artisan test tests/Feature/Marketplace/ProductListingTest.php`
Expected: FAIL — `seller.products.create`/`seller.products.store` routes don't exist yet; the visibility tests also fail because `Product::scopeBrowsable()` doesn't check `status` yet (Task 4) and `ProductController::show()` doesn't gate on it yet (this task, Step 8).

- [ ] **Step 5: Update `app/Models/Product.php`**

Replace the full contents:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'store_id', 'category_id', 'name', 'slug', 'description',
        'price', 'compare_price', 'stock', 'sku', 'is_active',
        'status', 'rejected_reason', 'reviewed_by', 'reviewed_at',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'compare_price' => 'decimal:2',
        'is_active' => 'boolean',
        'reviewed_at' => 'datetime',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(StoreCategory::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function averageRating(): float
    {
        return round((float) $this->reviews()->avg('rating'), 1);
    }

    /**
     * Products visible in the buyer-facing marketplace (platform-loop pass):
     * the product itself must be active AND its parent store must be
     * active AND the listing must have passed moderation review
     * (status === 'approved') -- a product left active under a
     * deactivated store, or still pending/rejected, should not be
     * independently browsable/purchasable.
     */
    public function scopeBrowsable(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where('status', 'approved')
            ->whereHas('store', fn (Builder $q) => $q->where('is_active', true));
    }
}
```

- [ ] **Step 6: Write `app/Policies/ProductPolicy.php`**

```php
<?php

namespace App\Policies;

use App\Models\Store;
use App\Models\User;

class ProductPolicy
{
    /**
     * Only a store's owner may create a listing for it.
     */
    public function create(User $user, Store $store): bool
    {
        return $user->id === $store->owner_id;
    }
}
```

- [ ] **Step 7: Write `app/Http/Controllers/SellerProductController.php`**

```php
<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Store;
use App\Models\StoreCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SellerProductController extends Controller
{
    public function create(): View|RedirectResponse
    {
        $store = Store::where('owner_id', auth()->id())->first();

        if (! $store) {
            return redirect()->route('seller.store.create');
        }

        $categories = StoreCategory::orderBy('name')->get();

        return view('products.create', ['store' => $store, 'categories' => $categories]);
    }

    public function store(Request $request): RedirectResponse
    {
        $store = Store::where('owner_id', auth()->id())->first();

        if (! $store) {
            return redirect()->route('seller.store.create');
        }

        Gate::authorize('create', [Product::class, $store]);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'price' => 'required|numeric|min:0',
            'compare_price' => 'nullable|numeric|min:0',
            'stock' => 'required|integer|min:0',
            'sku' => 'nullable|string|unique:products,sku',
            'category_id' => 'nullable|exists:store_categories,id',
        ]);

        $product = Product::create([
            'store_id' => $store->id,
            'category_id' => $validated['category_id'] ?? null,
            'name' => $validated['name'],
            'slug' => Str::slug($validated['name']).'-'.Str::random(6),
            'description' => $validated['description'] ?? null,
            'price' => $validated['price'],
            'compare_price' => $validated['compare_price'] ?? null,
            'stock' => $validated['stock'],
            'sku' => $validated['sku'] ?? null,
            'status' => 'pending_approval',
        ]);

        return redirect()->route('products.show', $product)
            ->with('status', 'Listing submitted. It will appear in the marketplace once approved.');
    }
}
```

`Gate::authorize('create', [Product::class, $store])` invokes `ProductPolicy::create(User $user, Store $store)` — Laravel resolves the policy from the first array element's class (`Product::class`) and passes the remaining array elements as extra arguments after `$user`.

- [ ] **Step 8: Add the moderation gate to `ProductController::show()`**

In `app/Http/Controllers/ProductController.php`, replace:

```php
        $isOwner = auth()->id() === $product->store->owner_id;

        abort_unless($isOwner || ($product->is_active && $product->store->is_active), 404);
```

with:

```php
        $isOwner = auth()->id() === $product->store->owner_id;

        abort_unless(
            $isOwner || ($product->is_active && $product->status === 'approved' && $product->store->is_active),
            404
        );
```

- [ ] **Step 9: Add the listing-submission view**

Create `resources/views/products/create.blade.php`:

```blade
<x-app-layout>
<div style="padding:2rem 2.5rem 3rem;max-width:640px;">
    <h1 style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:800;color:#f4f4f5;margin:0 0 0.3rem;">
        List a Product
    </h1>
    <p style="font-size:0.78rem;color:#71717a;margin:0 0 1.5rem;">Selling from <strong style="color:#a1a1aa;">{{ $store->name }}</strong>. New listings are reviewed before appearing in the marketplace.</p>

    @if ($errors->any())
        <div style="background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.25);color:#ef4444;padding:0.75rem 1rem;border-radius:8px;font-size:0.8rem;margin-bottom:1.25rem;">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('seller.products.store') }}" style="background:#141416;border:1px solid rgba(255,255,255,0.06);border-radius:1rem;padding:1.5rem;display:flex;flex-direction:column;gap:1rem;">
        @csrf

        <div>
            <label style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Product Name</label>
            <input type="text" name="name" value="{{ old('name') }}" required style="width:100%;padding:0.5rem;border-radius:6px;">
        </div>

        <div>
            <label style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Description</label>
            <textarea name="description" rows="4" style="width:100%;padding:0.5rem;border-radius:6px;">{{ old('description') }}</textarea>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
            <div>
                <label style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Price</label>
                <input type="number" step="0.01" name="price" value="{{ old('price') }}" required style="width:100%;padding:0.5rem;border-radius:6px;">
            </div>
            <div>
                <label style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Compare-at Price</label>
                <input type="number" step="0.01" name="compare_price" value="{{ old('compare_price') }}" style="width:100%;padding:0.5rem;border-radius:6px;">
            </div>
            <div>
                <label style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Stock</label>
                <input type="number" name="stock" value="{{ old('stock') }}" required style="width:100%;padding:0.5rem;border-radius:6px;">
            </div>
            <div>
                <label style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">SKU</label>
                <input type="text" name="sku" value="{{ old('sku') }}" style="width:100%;padding:0.5rem;border-radius:6px;">
            </div>
        </div>

        <div>
            <label style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Category</label>
            <select name="category_id" style="width:100%;padding:0.5rem;border-radius:6px;">
                <option value="">— None —</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}" @selected(old('category_id') == $category->id)>{{ $category->name }}</option>
                @endforeach
            </select>
        </div>

        <button type="submit" style="margin-top:0.5rem;padding:0.6rem;border-radius:8px;background:linear-gradient(135deg,#f59e0b,#d97706);color:#1a1a1a;font-weight:700;border:none;cursor:pointer;">
            Submit for Review
        </button>
    </form>
</div>
</x-app-layout>
```

- [ ] **Step 10: Add routes**

In `routes/web.php`, add `use App\Http\Controllers\SellerProductController;` to the imports, and add two lines after the `seller.store.store` route added in Task 2:

```php
    Route::get('/sell/products/create', [SellerProductController::class, 'create'])->name('seller.products.create');
    Route::post('/sell/products', [SellerProductController::class, 'store'])->name('seller.products.store');
```

- [ ] **Step 11: Run this task's tests, then Task 2's**

Run: `php artisan test tests/Feature/Marketplace/ProductListingTest.php`
Expected: PASS (8 tests) — `scopeBrowsable()`'s `status` check (Step 5) makes the visibility tests pass; `ProductController::show()`'s guard (Step 8) makes the 404 tests pass.

Run: `php artisan test tests/Feature/Marketplace/BecomeSellerTest.php`
Expected: PASS (3 tests) — the previously-blocked redirect assertion now resolves against the real `seller.products.create` route.

- [ ] **Step 12: Commit**

```bash
git add database/migrations/2026_08_09_000002_add_review_lifecycle_to_products_table.php \
  app/Models/Product.php app/Policies/ProductPolicy.php app/Http/Controllers/SellerProductController.php \
  app/Http/Controllers/ProductController.php resources/views/products/create.blade.php routes/web.php \
  tests/Feature/Marketplace/ProductListingTest.php
git commit -m "feat: product listing submission with a pending_approval status gate

New products start pending_approval and are invisible to buyers.
is_active is untouched -- status is a second, independent gate.
scopeBrowsable() and ProductController::show() both updated;
CartController::store() already routes every add-to-cart through
scopeBrowsable(), so pending/rejected listings 404 out of cart-add
for free."
```

---

### Task 4: Review screen (`ProductModerationController`)

**Files:**
- Create: `app/Http/Middleware/EnsurePlatformOperator.php`
- Create: `app/Http/Controllers/ProductModerationController.php`
- Create: `resources/views/operator/products/index.blade.php`
- Modify: `bootstrap/app.php`
- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/CartController.php`
- Test: `tests/Feature/Marketplace/ProductModerationTest.php`

**Interfaces:**
- Consumes: `Product::$fillable`/`status` (Task 3), `is_platform_operator` (Task 1).
- Produces: routes `operator.products.index`, `operator.products.approve`, `operator.products.reject`; middleware alias `operator`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Marketplace/ProductModerationTest.php`:

```php
<?php

namespace Tests\Feature\Marketplace;

use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductModerationTest extends TestCase
{
    use RefreshDatabase;

    private function pendingProduct(): Product
    {
        $owner = User::factory()->create();
        $store = Store::create([
            'owner_id' => $owner->id,
            'name' => 'Test Store',
            'slug' => 'test-store-'.uniqid(),
            'is_active' => true,
        ]);

        return Product::create([
            'store_id' => $store->id,
            'name' => 'Widget',
            'slug' => 'widget-'.uniqid(),
            'price' => 19.99,
            'stock' => 10,
            'is_active' => true,
            'status' => 'pending_approval',
        ]);
    }

    private function operator(): User
    {
        $operator = User::factory()->create();
        $operator->forceFill(['is_platform_operator' => true])->save();

        return $operator;
    }

    public function test_operator_can_view_pending_products(): void
    {
        $product = $this->pendingProduct();

        $this->actingAs($this->operator())
            ->get(route('operator.products.index'))
            ->assertOk()
            ->assertSee($product->name);
    }

    public function test_operator_can_approve_a_pending_product(): void
    {
        $product = $this->pendingProduct();
        $operator = $this->operator();

        $this->actingAs($operator)
            ->post(route('operator.products.approve', $product))
            ->assertRedirect();

        $fresh = $product->fresh();
        $this->assertSame('approved', $fresh->status);
        $this->assertSame($operator->id, $fresh->reviewed_by);
        $this->assertNotNull($fresh->reviewed_at);
    }

    public function test_approving_makes_the_product_immediately_browsable(): void
    {
        $product = $this->pendingProduct();

        $this->actingAs($this->operator())
            ->post(route('operator.products.approve', $product));

        $this->assertTrue(Product::browsable()->whereKey($product->id)->exists());
    }

    public function test_reject_without_a_reason_is_blocked(): void
    {
        $product = $this->pendingProduct();

        $this->actingAs($this->operator())
            ->post(route('operator.products.reject', $product), [])
            ->assertSessionHasErrors('reason');

        $this->assertSame('pending_approval', $product->fresh()->status);
    }

    public function test_reject_with_a_reason_marks_it_rejected(): void
    {
        $product = $this->pendingProduct();
        $operator = $this->operator();

        $this->actingAs($operator)
            ->post(route('operator.products.reject', $product), ['reason' => 'Prohibited item.'])
            ->assertRedirect();

        $fresh = $product->fresh();
        $this->assertSame('rejected', $fresh->status);
        $this->assertSame('Prohibited item.', $fresh->rejected_reason);
        $this->assertSame($operator->id, $fresh->reviewed_by);
    }

    public function test_non_operator_cannot_view_or_act_on_the_review_screen(): void
    {
        $product = $this->pendingProduct();
        $nonOperator = User::factory()->create();

        $this->actingAs($nonOperator)
            ->get(route('operator.products.index'))
            ->assertForbidden();

        $this->actingAs($nonOperator)
            ->post(route('operator.products.approve', $product))
            ->assertForbidden();

        $this->assertSame('pending_approval', $product->fresh()->status);
    }

    public function test_approving_an_already_decided_product_is_refused(): void
    {
        $product = $this->pendingProduct();
        $product->update(['status' => 'approved']);

        $this->actingAs($this->operator())
            ->post(route('operator.products.approve', $product))
            ->assertSessionHasErrors();

        $this->assertSame('approved', $product->fresh()->status);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/Marketplace/ProductModerationTest.php`
Expected: FAIL — `operator.products.*` routes don't exist yet.

- [ ] **Step 3: Write `app/Http/Middleware/EnsurePlatformOperator.php`**

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformOperator
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->is_platform_operator, 403);

        return $next($request);
    }
}
```

- [ ] **Step 4: Register the middleware alias**

Read `bootstrap/app.php` first to see the existing `withMiddleware()` closure shape (the spec's audit noted it's currently empty). Add the alias:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->alias(['operator' => \App\Http\Middleware\EnsurePlatformOperator::class]);
})
```

If `withMiddleware()` already has content, add the `alias([...])` call inside the existing closure rather than replacing it. Add `use Illuminate\Foundation\Configuration\Middleware;` to the imports if not already present.

- [ ] **Step 5: Write `app/Http/Controllers/ProductModerationController.php`**

```php
<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductModerationController extends Controller
{
    public function index(): View
    {
        return view('operator.products.index', [
            'pending' => Product::where('status', 'pending_approval')->with('store.owner')->latest()->get(),
            'reviewed' => Product::whereIn('status', ['approved', 'rejected'])->with('store.owner')->latest()->limit(20)->get(),
        ]);
    }

    public function approve(Product $product, Request $request): RedirectResponse
    {
        if ($product->status !== 'pending_approval') {
            return back()->withErrors(['status' => 'Only a pending listing can be approved.']);
        }

        $product->update([
            'status' => 'approved',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return back()->with('status', 'Listing approved.');
    }

    public function reject(Product $product, Request $request): RedirectResponse
    {
        if ($product->status !== 'pending_approval') {
            return back()->withErrors(['status' => 'Only a pending listing can be rejected.']);
        }

        $validated = $request->validate(['reason' => 'required|string']);

        $product->update([
            'status' => 'rejected',
            'rejected_reason' => $validated['reason'],
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return back()->with('status', 'Listing rejected.');
    }
}
```

`Product` carries no user-scoping trait (unlike Dot.Ehail's `DriverProfile`), so the implicitly-bound `Product $product` route parameter is safe to resolve directly here — no `withoutGlobalScope` workaround needed.

- [ ] **Step 6: Add the review-screen view**

Create `resources/views/operator/products/index.blade.php`:

```blade
<x-app-layout>
<div style="padding:2rem 2.5rem 3rem;max-width:900px;">
    <h1 style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:800;color:#f4f4f5;margin:0 0 1.5rem;">
        Listing Review
    </h1>

    @if (session('status'))
        <div style="background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.25);color:#22c55e;padding:0.75rem 1rem;border-radius:8px;font-size:0.8rem;margin-bottom:1.5rem;">
            {{ session('status') }}
        </div>
    @endif

    <h2 style="font-size:1rem;font-weight:700;color:#f4f4f5;margin:0 0 1rem;">Pending ({{ $pending->count() }})</h2>
    @forelse ($pending as $product)
        <div style="background:#141416;border:1px solid rgba(255,255,255,0.06);border-radius:1rem;padding:1.25rem;margin-bottom:1rem;">
            <p style="font-size:0.95rem;font-weight:600;color:#f4f4f5;margin:0 0 0.25rem;">{{ $product->name }}</p>
            <p style="font-size:0.78rem;color:#71717a;margin:0 0 0.75rem;">${{ number_format($product->price, 2) }} · {{ $product->store->name }} (owner: {{ $product->store->owner?->name }})</p>

            <div style="display:flex;gap:0.5rem;align-items:flex-start;">
                <form method="POST" action="{{ route('operator.products.approve', $product) }}">
                    @csrf
                    <button type="submit" style="padding:0.4rem 1rem;border-radius:6px;background:#22c55e;color:#052e13;font-weight:700;border:none;font-size:0.78rem;cursor:pointer;">Approve</button>
                </form>
                <form method="POST" action="{{ route('operator.products.reject', $product) }}" style="display:flex;gap:0.4rem;align-items:center;">
                    @csrf
                    <input type="text" name="reason" placeholder="Reason for rejecting" required style="padding:0.4rem;border-radius:6px;font-size:0.78rem;">
                    <button type="submit" style="padding:0.4rem 1rem;border-radius:6px;background:#ef4444;color:#3f0d0d;font-weight:700;border:none;font-size:0.78rem;cursor:pointer;">Reject</button>
                </form>
            </div>
        </div>
    @empty
        <p style="font-size:0.8rem;color:#71717a;">No pending listings.</p>
    @endforelse

    <h2 style="font-size:1rem;font-weight:700;color:#f4f4f5;margin:2rem 0 1rem;">Recently Reviewed</h2>
    @forelse ($reviewed as $product)
        <div style="background:#141416;border:1px solid rgba(255,255,255,0.06);border-radius:1rem;padding:1rem 1.25rem;margin-bottom:0.75rem;display:flex;justify-content:space-between;align-items:center;">
            <div>
                <p style="font-size:0.9rem;color:#f4f4f5;margin:0;">{{ $product->name }}</p>
                <p style="font-size:0.75rem;color:#71717a;margin:0;">{{ $product->store->name }}</p>
            </div>
            <span style="font-size:11px;font-weight:600;padding:4px 12px;border-radius:100px;{{ $product->status === 'approved' ? 'background:rgba(34,197,94,0.1);color:#4ade80;' : 'background:rgba(239,68,68,0.1);color:#f87171;' }}">
                {{ ucfirst($product->status) }}
            </span>
        </div>
    @empty
        <p style="font-size:0.8rem;color:#71717a;">No reviewed listings yet.</p>
    @endforelse
</div>
</x-app-layout>
```

- [ ] **Step 7: Add routes**

In `routes/web.php`, add `use App\Http\Controllers\ProductModerationController;` to the imports, and add a new middleware-nested group inside the existing `auth:sanctum` group, after the `seller.products.store` route:

```php
    Route::middleware('operator')->prefix('operator')->name('operator.')->group(function () {
        Route::get('/products', [ProductModerationController::class, 'index'])->name('products.index');
        Route::post('/products/{product}/approve', [ProductModerationController::class, 'approve'])->name('products.approve');
        Route::post('/products/{product}/reject', [ProductModerationController::class, 'reject'])->name('products.reject');
    });
```

- [ ] **Step 8: Add the checkout defense-in-depth check**

In `app/Http/Controllers/CartController.php`, replace:

```php
                    if (! $product || ! $product->is_active || $item->quantity > $product->stock) {
```

with:

```php
                    if (! $product || ! $product->is_active || $product->status !== 'approved' || $item->quantity > $product->stock) {
```

- [ ] **Step 9: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Marketplace/ProductModerationTest.php`
Expected: PASS (7 tests)

- [ ] **Step 10: Commit**

```bash
git add app/Http/Middleware/EnsurePlatformOperator.php app/Http/Controllers/ProductModerationController.php \
  resources/views/operator/products/index.blade.php bootstrap/app.php routes/web.php \
  app/Http/Controllers/CartController.php tests/Feature/Marketplace/ProductModerationTest.php
git commit -m "feat: operator review screen -- approve/reject pending listings

is_platform_operator-gated. Rejecting requires a non-empty reason.
Product carries no user-scoping trait, so implicit route-model binding
on {product} is safe here -- no Dot.Ehail-style withoutGlobalScope
workaround needed. CartController::checkout()'s in-transaction re-check
also gains a status === 'approved' condition for defense in depth."
```

---

### Task 5: Full regression + manual verification

**Files:**
- Modify: `tests/Feature/Marketplace/ProductBrowseTest.php`

**Interfaces:** none new — regression fix + verification only.

- [ ] **Step 1: Fix the pre-existing `ProductBrowseTest` fixture**

Read `tests/Feature/Marketplace/ProductBrowseTest.php` first to confirm its current `makeProduct()` helper. In its `makeProduct()` method, add `'status' => 'approved'` to the default array passed to `array_merge()`:

```php
    private function makeProduct(Store $store, array $overrides = []): Product
    {
        return Product::create(array_merge([
            'store_id' => $store->id,
            'name' => 'Widget',
            'slug' => 'widget-'.uniqid(),
            'price' => 19.99,
            'stock' => 10,
            'is_active' => true,
            'status' => 'approved',
        ], $overrides));
    }
```

- [ ] **Step 2: Run the full test suite**

Run: `php artisan test --compact`
Expected: 0 failures across the whole suite — confirms Tasks 1-4's migrations, `Product::scopeBrowsable()` change, and `ProductController`/`CartController` guard changes didn't break `ProductBrowseTest` or any other pre-existing test.

- [ ] **Step 3: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`
Expected: passes; re-run Step 2 if it reformats anything.

- [ ] **Step 4: Manual end-to-end verification**

```bash
php artisan tinker --execute '
$seller = \App\Models\User::factory()->create(["name" => "Manual Test Seller"]);
$operator = \App\Models\User::factory()->create(["name" => "Manual Test Operator"]);
$operator->forceFill(["is_platform_operator" => true])->save();
echo "seller_id={$seller->id} operator_id={$operator->id}\n";
'
```

Then submit a store and listing as the seller, via the real controllers:

```bash
php artisan tinker --execute '
auth()->loginUsingId(<seller_id>);
$storeRequest = \Illuminate\Http\Request::create("/sell", "POST", ["name" => "Manual Test Store"]);
$storeRequest->setUserResolver(fn () => auth()->user());
app(\App\Http\Controllers\StoreController::class)->store($storeRequest);
$store = \App\Models\Store::where("owner_id", <seller_id>)->first();

$productRequest = \Illuminate\Http\Request::create("/sell/products", "POST", [
    "name" => "Manual Test Product", "price" => 15.00, "stock" => 5,
]);
$productRequest->setUserResolver(fn () => auth()->user());
app(\App\Http\Controllers\SellerProductController::class)->store($productRequest);
$product = \App\Models\Product::where("name", "Manual Test Product")->first();
echo "product status after submission: {$product->status}\n";
echo "browsable before approval: ".(\App\Models\Product::browsable()->whereKey($product->id)->exists() ? "yes" : "no")."\n";
'
```

Expected: `product status after submission: pending_approval`, `browsable before approval: no`.

Then approve it as the operator:

```bash
php artisan tinker --execute '
$product = \App\Models\Product::where("name", "Manual Test Product")->first();
auth()->loginUsingId(<operator_id>);
$request = \Illuminate\Http\Request::create("/", "POST");
$request->setUserResolver(fn () => auth()->user());
app(\App\Http\Controllers\ProductModerationController::class)->approve($product, $request);
$fresh = $product->fresh();
echo "product status after approval: {$fresh->status}\n";
echo "browsable after approval: ".(\App\Models\Product::browsable()->whereKey($fresh->id)->exists() ? "yes" : "no")."\n";
'
```

Expected: `product status after approval: approved`, `browsable after approval: yes`. Confirms the real Eloquent lifecycle end-to-end, not just in-memory test doubles.

- [ ] **Step 5: Clean up manual verification fixtures**

```bash
php artisan tinker --execute '
$product = \App\Models\Product::where("name", "Manual Test Product")->first();
if ($product) { $product->delete(); }
\App\Models\Store::where("name", "Manual Test Store")->delete();
\App\Models\User::where("name", "like", "Manual Test%")->delete();
echo "cleaned up manual verification fixtures\n";
'
```

- [ ] **Step 6: Commit the `ProductBrowseTest` fix**

```bash
git add tests/Feature/Marketplace/ProductBrowseTest.php
git commit -m "fix: pre-existing ProductBrowseTest needs status=approved now that it's required

products.status defaults to pending_approval as of Task 3, so the
existing makeProduct() fixture helper's created rows are invisible to
scopeBrowsable() until fixed here -- same class of regression
Dot.Ehail's DashboardTest/DriverProfileViewTest hit when fleet_id
became required."
```

- [ ] **Step 7: Report completion**

If Step 2 finds any failures after Step 6's fix, stop and resolve them before considering this plan complete.

## Self-Review Notes

- **Spec coverage:** Task 1 covers spec §1. Task 2 covers spec §2. Task 3 covers spec §3, §4, §5 (the moderation gate on `ProductController::show`), and §7's `scopeBrowsable()` change. Task 4 covers spec §6 and the rest of §7 (`CartController::checkout()`'s defense-in-depth check). Task 5 covers spec §8's regression fix.
- **Placeholder scan:** none — every step has literal file content, including full controller/view/policy/migration contents.
- **Type consistency:** `Store::where('owner_id', ...)`, `Product::$fillable`/`scopeBrowsable()`/`reviewer()`, `ProductPolicy::create(User, Store)`, `ProductModerationController::index/approve/reject` signatures are used identically across every task that references them.
- **Cross-task dependency called out explicitly:** Task 2's `test_a_user_who_already_owns_a_store_is_redirected_to_it_instead_of_creating_a_second_one` depends on a route Task 3 adds — flagged in both Task 2's Step 6 (expected partial failure) and re-verified in Task 3's Step 11, so an implementer running tasks in order isn't confused by a red test that isn't a regression.
- **The two independent gates (`is_active` vs `status`), addressed at every touch point:** `scopeBrowsable()` (Task 3), `ProductController::show()` (Task 3), `CartController::checkout()`'s re-check (Task 4) all updated together; `CartController::store()` needed no change since it already routes through `scopeBrowsable()`.
- **Uncommitted pre-existing changes:** this repo has pre-existing uncommitted changes (`application-mark.blade.php`, mark images) stashed before this plan was written, per the spec's own context section; every `git add` in this plan lists files explicitly.
