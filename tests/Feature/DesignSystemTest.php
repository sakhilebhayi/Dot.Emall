<?php

namespace Tests\Feature;

use App\Support\Icons;
use App\Support\Status;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\Concerns\AssertsResponsiveShell;
use Tests\Concerns\BuildsMarketplace;
use Tests\TestCase;

/** The design system's contract: layouts, kit, status vocabulary, no third parties, no literal colours, honest copy. */
class DesignSystemTest extends TestCase
{
    use AssertsResponsiveShell, BuildsMarketplace, RefreshDatabase;

    private function makeOperatorUser(): \App\Models\User
    {
        $user = \App\Models\User::factory()->withPersonalTeam()->create();
        $user->forceFill(['is_platform_operator' => true])->save();

        return $user;
    }

    private function views(): string
    {
        return dirname(__DIR__, 2).'/resources/views';
    }

    /** @return list<string> */
    private function bladeFiles(string $dir): array
    {
        $out = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $f) {
            if (str_ends_with((string) $f, '.blade.php')) {
                $out[] = (string) $f;
            }
        }

        return $out;
    }

    public function test_the_kit_gallery_renders_every_component_in_the_local_environment(): void
    {
        $html = $this->get('/dev/kit')->assertOk()->getContent();

        foreach (['ds-label', 'ds-pcard', 'ds-gallery', 'ds-waybill', 'ds-cartline', 'ds-trust', 'ds-facet', 'ds-stepper', 'ds-option', 'ds-dialog', 'ds-tabs', 'ds-table', 'ds-search', 'ds-steps', 'ds-review', 'ds-stat', 'ds-empty'] as $class) {
            $this->assertStringContainsString($class, $html, "the kit shows {$class}");
        }
    }

    public function test_the_four_layout_contexts_and_the_auth_frame_share_the_shell_contract(): void
    {
        $seller = $this->makeSeller();
        $this->makeListing($seller, ['name' => 'Contract Teapot']);

        $this->assertResponsiveShell($this->get('/'));
        $this->assertResponsiveShell($this->get(route('products.index')));
        $this->assertResponsiveShell($this->actingAs($this->makeBuyer())->get(route('orders.index')));
        $this->assertResponsiveShell($this->actingAs($seller)->get(route('seller.products.index')), 'desk-menu');
        $this->assertResponsiveShell($this->actingAs($this->makeOperatorUser())->get(route('operator.products.index')), 'desk-menu');
    }

    public function test_every_page_has_its_own_title_and_the_auth_pages_have_one_h1(): void
    {
        $titles = [];
        foreach (['/', '/login', '/forgot-password', '/products', '/terms-of-service', '/privacy-policy', '/cookies'] as $url) {
            $x = $this->shellXPath($this->get($url)->assertOk());
            $this->assertSame(1, $x->query('//h1')->length, "{$url} has one h1");
            $title = $x->query('//title')->item(0)->textContent;
            $this->assertStringEndsWith('| Dot.Emall', $title);
            $titles[] = $title;
        }
        $this->assertCount(count($titles), array_unique($titles), 'titles are unique per page');
    }

    public function test_sign_in_is_truthful_and_there_are_no_dead_sign_up_links(): void
    {
        foreach (['/', '/login', '/products', '/terms-of-service'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('href="'.url('/register').'"', $html, "{$url} links to the closed sign-up");
            $this->assertStringNotContainsString('Create account', $html);
            $this->assertStringContainsString('Accounts come from InfoDot', $html, "{$url} says where accounts come from");
        }
        $login = $this->get('/login')->getContent();
        $this->assertStringContainsString('Sign in with InfoDot', $login);
    }

    public function test_error_pages_render_standalone_with_one_h1(): void
    {
        foreach ([403, 404, 419, 429, 500, 503] as $code) {
            $html = view("errors.{$code}")->render();
            $this->assertSame(1, substr_count($html, '<h1'), "{$code} has one h1");
            $this->assertStringContainsString('Skip to main content', $html);
            $this->assertStringNotContainsString('livewire', strtolower($html), 'the error frame needs no Livewire, session or database');
            $this->assertStringNotContainsString('csrf-token', $html);
        }
    }

    public function test_nothing_loads_from_a_cdn_or_an_icon_font(): void
    {
        $all = '';
        foreach ($this->bladeFiles($this->views()) as $file) {
            $all .= file_get_contents($file);
        }
        foreach (glob(dirname(__DIR__, 2).'/resources/css/*.css') as $css) {
            $all .= file_get_contents($css);
        }
        foreach (['cdn.tailwindcss.com', 'fonts.googleapis.com', 'fonts.gstatic.com', 'unpkg.com', 'jsdelivr', 'images.unsplash.com', 'ui-avatars.com', 'material-symbols', 'Material Symbols'] as $needle) {
            $this->assertStringNotContainsString($needle, $all, "{$needle} is gone");
        }
        $this->assertFileExists(dirname(__DIR__, 2).'/public/fonts/schibsted-grotesk-var-latin.woff2');
        $this->assertFileExists(dirname(__DIR__, 2).'/public/fonts/martian-mono-var-latin.woff2');
        $this->assertFileExists(dirname(__DIR__, 2).'/public/fonts/LICENSE-SchibstedGrotesk-OFL.txt');
        $this->assertFileExists(dirname(__DIR__, 2).'/public/fonts/LICENSE-MartianMono-OFL.txt');
    }

    public function test_layouts_and_components_carry_no_literal_colour(): void
    {
        foreach (array_merge($this->bladeFiles($this->views().'/components'), $this->bladeFiles($this->views().'/layouts'), $this->bladeFiles($this->views().'/errors'), $this->bladeFiles($this->views().'/auth'), [$this->views().'/welcome.blade.php']) as $file) {
            $source = preg_replace('/\{\{--.*?--\}\}/s', '', file_get_contents($file));
            $this->assertDoesNotMatchRegularExpression('/(?<![\w&"\'=\/])#[0-9a-fA-F]{6}\b/', $source, basename($file).' has a literal hex colour');
            $this->assertDoesNotMatchRegularExpression('/\brgba?\(/', $source, basename($file).' has a literal rgb colour');
        }
    }

    public function test_every_kit_component_documents_its_props_at_the_top(): void
    {
        foreach (glob($this->views().'/components/ds/*.blade.php') as $file) {
            $this->assertStringStartsWith('{{--', ltrim(file_get_contents($file)), basename($file).' starts with its documentation');
        }
        $this->assertGreaterThanOrEqual(45, count(glob($this->views().'/components/ds/*.blade.php')));
    }

    public function test_the_status_vocabulary_gives_every_state_a_word_a_shape_and_a_tone(): void
    {
        $shapes = [];
        foreach ([Status::ORDER, Status::PRODUCT] as $table) {
            foreach ($table as $state => [$word, $icon, $tone]) {
                $this->assertNotSame('', $word);
                $this->assertTrue(Icons::has($icon), "{$state} icon {$icon} exists");
                $this->assertContains($tone, ['pos', 'cau', 'crit', 'info', 'neutral']);
            }
        }
        foreach (['awaiting_payment', 'paid', 'packing', 'shipped', 'delivered', 'cancelled', 'refunded', 'return_requested', 'return_approved', 'return_declined'] as $s) {
            $this->assertArrayHasKey($s, Status::ORDER);
            $shapes[] = Status::ORDER[$s][1];
        }
        foreach (['in_stock', 'low_stock', 'out_of_stock', 'unlisted', 'pending_review', 'rejected'] as $s) {
            $this->assertArrayHasKey($s, Status::PRODUCT);
            $shapes[] = Status::PRODUCT[$s][1];
        }
        $this->assertSame($shapes, array_values(array_unique($shapes)), 'no two states share a shape');
    }

    public function test_low_stock_is_only_ever_a_real_quantity_of_five_or_fewer(): void
    {
        $this->assertSame('in_stock', Status::stockState(6));
        $this->assertSame('low_stock', Status::stockState(5));
        $this->assertSame('low_stock', Status::stockState(1));
        $this->assertSame('out_of_stock', Status::stockState(0));
        $this->assertSame('Only 3 left', Status::resolve('product', 'low_stock', 3)['word']);

        $html = Blade::render('<x-ds.product-card href="#" title="A" :price-cents="1000" :stock="12" />');
        $this->assertStringNotContainsString('left', $html, 'no scarcity text above five');
        $this->assertStringContainsString('Only 4 left', Blade::render('<x-ds.product-card href="#" title="A" :price-cents="1000" :stock="4" />'));
        $this->assertStringContainsString('Out of stock', Blade::render('<x-ds.product-card href="#" title="A" :price-cents="1000" :stock="0" />'));
    }

    public function test_price_prints_a_plain_amount_and_a_real_reference_only_when_given(): void
    {
        $html = Blade::render('<x-ds.price :cents="74900" />');
        $this->assertStringContainsString('R749.00', $html);
        $this->assertStringContainsString('ds-price__whole">749<', $html);

        $this->assertStringNotContainsString('Lowest price', Blade::render('<x-ds.price :cents="74900" />'));
        $this->assertStringContainsString('Lowest price in the last 30 days', Blade::render('<x-ds.price :cents="74900" :previous-cents="89900" />'));
    }

    public function test_the_price_label_states_price_delivery_and_total_and_never_guesses(): void
    {
        $known = Blade::render('<x-ds.price-label :price-cents="74900" :delivery-cents="6500" />');
        $this->assertStringContainsString('R814.00', $known, 'total is price plus delivery');

        $unknown = Blade::render('<x-ds.price-label :price-cents="74900" />');
        $this->assertStringContainsString('Enter a delivery address to see this', $unknown);
        $this->assertStringContainsString('Not known yet', $unknown);

        $free = Blade::render('<x-ds.price-label :price-cents="74900" :delivery-cents="0" />');
        $this->assertStringContainsString('Free', $free);
    }

    public function test_a_rating_is_not_drawn_for_zero_reviews(): void
    {
        $this->assertSame('', trim(Blade::render('<x-ds.rating :value="0" :count="0" />')));
        $html = Blade::render('<x-ds.rating :value="4.6" :count="120" />');
        $this->assertStringContainsString('Rated 4.6 out of 5 from 120 reviews', $html);
    }

    public function test_navigation_links_through_the_safe_helper_and_marks_pending_routes(): void
    {
        $this->assertSame('#', emall_route('definitely.not.registered'));
        $this->assertSame('data-pending-route="definitely.not.registered"', emall_pending('definitely.not.registered'));
        $this->assertSame('', emall_pending('products.index'));
        $this->assertSame(route('products.index'), emall_route('products.index'));
        $this->assertNull(emall_pending_name('products.index'));
        $this->assertSame(0, \App\Support\Shell::cartCount(), 'an empty cart counts 0');

        $html = $this->get('/')->getContent();
        $this->assertStringNotContainsString('data-pending-route="help.returns"', $html, 'the help pages exist now (W5), so the footer link resolves');
    }

    public function test_the_dev_gallery_is_registered_only_inside_the_local_and_testing_block(): void
    {
        $routes = file_get_contents(dirname(__DIR__, 2).'/routes/web.php');
        $block = substr($routes, strpos($routes, '// --- dev ---'), strpos($routes, '// --- end dev ---') - strpos($routes, '// --- dev ---'));

        $this->assertStringContainsString("app()->environment('local', 'testing')", $block);
        $this->assertStringContainsString("Route::view('/dev/kit'", $block);
        $this->assertSame(1, substr_count($routes, "Route::view('/dev/kit'"), 'the gallery is registered once, inside the block');
    }

    public function test_visible_copy_in_the_shell_has_no_dashes_and_no_exclamation_marks(): void
    {
        foreach (array_merge($this->bladeFiles($this->views().'/components'), $this->bladeFiles($this->views().'/layouts'), $this->bladeFiles($this->views().'/errors'), $this->bladeFiles($this->views().'/auth'), [$this->views().'/welcome.blade.php']) as $file) {
            $source = preg_replace(['/\{\{--.*?--\}\}/s', '/<script.*?<\/script>/s', '/@php.*?@endphp/s', '/\{\{.*?\}\}/s', '/\{!!.*?!!\}/s'], '', file_get_contents($file));
            $text = strip_tags(preg_replace('/<[^>]+>/', '>', $source));
            $this->assertStringNotContainsString('—', $source, basename($file).' has an em dash');
            $this->assertStringNotContainsString('–', $source, basename($file).' has an en dash');
            $this->assertDoesNotMatchRegularExpression('/[A-Za-z]!(\s|$)/', $text, basename($file).' has an exclamation mark');
        }
    }
}
