<?php

namespace Database\Seeders;

use App\Models\Collection;
use App\Models\Product;
use App\Models\ProductAlert;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Cart\CartManager;
use App\Services\Community\ProductQuestions;
use App\Services\Discovery\AlertEvaluator;
use App\Services\Discovery\Collections;
use App\Services\Discovery\ProductAlerts;
use App\Services\Discovery\RecentlyViewed;
use App\Services\Discovery\SavedSearches;
use App\Services\Discovery\SearchLog;
use App\Services\Discovery\Track;
use App\Services\Discovery\Wishlists;
use App\Services\Notifications\NotificationPreferences;
use App\Services\Search\SearchEngine;
use App\Services\Search\SearchRequest;
use App\Support\Actor;
use Closure;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * What shoppers do before and around buying, made through the discovery services with the clock moved back: wishlists,
 * saved searches, alerts (one of which has fired), what people viewed and searched for, questions and answers,
 * collections and an abandoned cart. Anonymous visitors are fixed tokens so the seeder can find (and remove) their
 * keyed-hash rows again.
 */
final class EmallDemoEngagement
{
    public const VISITORS = 8;

    /**
     * @param  array<string, User>  $users
     * @param  array<string, Product>  $products
     * @param  Closure(float, callable, int=): mixed  $at  runs a callback with the clock moved back that many days
     */
    public function __construct(
        private readonly EmallDemoSeeder $seeder,
        private readonly array $users,
        private readonly array $products,
        private readonly Closure $at,
    ) {}

    public static function token(int $n): string
    {
        return 'demo-visitor-'.str_pad((string) $n, 2, '0', STR_PAD_LEFT).'-token-xxxxxxxx';
    }

    private function visitor(int $n): Actor
    {
        return Actor::forToken(self::token($n));
    }

    private function p(string $name): Product
    {
        return $this->products[$name];
    }

    private function at(float $days, callable $fn, int $hour = 11): mixed
    {
        return ($this->at)($days, $fn, $hour);
    }

    public function run(): void
    {
        // Saved searches and collections read the index, so build it now (it is rebuilt at the end with sales counts).
        Artisan::call('emall:search-reindex');

        $this->wishlists();
        $this->alerts();
        $this->views();
        $this->searches();
        $this->questions();
        $this->collections();
        $this->preferences();
        $this->abandonedCarts();

        // Evaluate the alerts the way the scheduler does: the MacBook is cheaper than the price Pieter was shown.
        app(AlertEvaluator::class)->run();
    }

    private function wishlists(): void
    {
        $lists = app(Wishlists::class);

        $variantOf = fn (string $name, array $choices) => ProductVariant::query()->where('product_id', $this->p($name)->id)->get()->first(fn ($v) => ($v->options ?? []) == $choices)?->id;

        $rows = [
            ['thandi', 'Samsung 43 Inch Full HD Smart TV', null, 'For the bedroom', 12],
            ['thandi', 'Air Fryer 5.5L Digital', null, null, 9],
            ['thandi', 'Merino Base Layer Tee', ['Size' => 'M', 'Colour' => 'Navy'], 'Birthday present for Sipho', 7],
            ['pieter', 'Apple MacBook Air 13 inch M2', null, null, 20],
            ['pieter', 'Wireless Over-Ear Headphones', null, null, 18],
            ['pieter', 'Trail Running Shoes', ['Shoe size' => '9', 'Colour' => 'Black'], null, 6],
            ['naledi', 'Terracotta Bud Vase', null, 'Waiting for the next firing', 15],
            ['naledi', 'Bar Fridge 95L', null, null, 11],
            ['naledi', 'Moringa Shampoo 300ml', null, null, 4],
            ['naledi', 'Samsung Galaxy Tab A9 Tablet', null, null, 3],
            ['ayesha', 'Cotton Duvet Set', ['Size' => 'Queen', 'Colour' => 'Grey'], null, 10],
            ['ayesha', 'Road Bike Helmet', ['Size' => 'M', 'Colour' => 'White'], null, 5],
            ['sipho', 'Camp Cookset, 8 Piece', null, null, 8],
            ['sipho', 'Mechanical Keyboard 75%', null, 'When payday comes', 2],
        ];

        foreach ($rows as [$buyer, $name, $choices, $note, $daysAgo]) {
            $this->at($daysAgo, fn () => $lists->add($this->users[$buyer], $this->p($name), $choices ? $variantOf($name, $choices) : null, $note));
        }
    }

    private function alerts(): void
    {
        $alerts = app(ProductAlerts::class);

        $this->at(15, fn () => $alerts->subscribe($this->users['naledi'], $this->p('Terracotta Bud Vase'), ProductAlert::BACK_IN_STOCK));
        $this->at(6, fn () => $alerts->subscribe($this->users['thandi'], $this->p('Clear Phone Case'), ProductAlert::BACK_IN_STOCK));
        $this->at(9, fn () => $alerts->subscribe($this->users['thandi'], $this->p('Samsung 65 Inch QLED 4K Smart TV'), ProductAlert::PRICE_DROP, 19999));
        $this->at(8, fn () => $alerts->subscribe($this->users['sipho'], $this->p('Dell Inspiron 15 Laptop'), ProductAlert::PRICE_DROP));

        // Pieter set this alert when the MacBook cost R21,999 (it has since been reduced to R20,999). The alert stores the
        // price the person saw, so that is what it is compared with.
        $this->at(20, fn () => $alerts->subscribe($this->users['pieter'], $this->p('Apple MacBook Air 13 inch M2'), ProductAlert::PRICE_DROP, null));
        DB::table('product_alerts')->where('user_id', $this->users['pieter']->id)->where('product_id', $this->p('Apple MacBook Air 13 inch M2')->id)->update(['baseline_price' => 21999]);

        // Saved searches.
        $searches = app(SavedSearches::class);
        $this->at(14, fn () => $searches->save($this->users['thandi'], 'Laptops under R10,000', SearchRequest::fromArray(['q' => 'laptop under R10,000']), 'weekly'));
        $this->at(12, fn () => $searches->save($this->users['pieter'], 'Samsung TVs', SearchRequest::fromArray(['q' => 'samsung tv']), 'daily'));
        $this->at(7, fn () => $searches->save($this->users['naledi'], 'Camping gear in stock', SearchRequest::fromArray(['category' => 'camping-hiking', 'in_stock' => 1]), 'off'));
    }

    private function views(): void
    {
        $recent = app(RecentlyViewed::class);
        $track = app(Track::class);

        $signedIn = [
            'thandi' => [['Samsung 43 Inch Full HD Smart TV', 12], ['Air Fryer 5.5L Digital', 9], ['Merino Base Layer Tee', 7], ['Samsung 65 Inch QLED 4K Smart TV', 9], ['Lenovo IdeaPad 5, 16GB RAM, 16 inch', 3]],
            'pieter' => [['Apple MacBook Air 13 inch M2', 20], ['Wireless Over-Ear Headphones', 18], ['Trail Running Shoes', 6], ['Dell Inspiron 15 Laptop', 5]],
            'naledi' => [['Bar Fridge 95L', 11], ['Moringa Shampoo 300ml', 4], ['Samsung Galaxy Tab A9 Tablet', 3], ['Camp Cookset, 8 Piece', 2]],
            'ayesha' => [['Cotton Duvet Set', 10], ['Road Bike Helmet', 5], ['Fynbos Soy Candle', 1]],
            'sipho' => [['Dell Inspiron 15 Laptop', 8], ['Mechanical Keyboard 75%', 2], ['Camp Cookset, 8 Piece', 8]],
        ];

        foreach ($signedIn as $buyer => $views) {
            foreach ($views as [$name, $daysAgo]) {
                $this->at($daysAgo, fn () => $recent->view(Actor::forUser($this->users[$buyer]), $this->p($name), 'search'));
            }
        }

        // People who looked at one television looked at the others (six visitors), and at the laptops (six visitors).
        foreach (range(1, 6) as $n) {
            foreach (['Samsung 55 Inch Crystal UHD Smart TV', 'Samsung 65 Inch QLED 4K Smart TV', 'LG 55 Inch UHD Smart TV'] as $name) {
                $this->at(2 + $n, fn () => $recent->view($this->visitor($n), $this->p($name), 'search'));
            }
        }

        foreach (range(3, 8) as $n) {
            foreach (['Lenovo IdeaPad Slim 3, 15.6 inch', 'Dell Inspiron 15 Laptop', 'HP 250 G9 Business Laptop'] as $name) {
                $this->at(1 + $n % 5, fn () => $recent->view($this->visitor($n), $this->p($name), 'category'));
            }
        }

        // The funnel: some searched, some added to the cart, a few started checkout.
        foreach ([1, 2, 3, 4] as $n) {
            $this->at(3, function () use ($track, $n) {
                $track->addToCart($this->visitor($n), $this->p('Samsung 55 Inch Crystal UHD Smart TV'));
                $track->checkoutStarted($this->visitor($n));
            });
        }

        $this->at(3, fn () => $track->addToCart($this->visitor(5), $this->p('Lenovo IdeaPad Slim 3, 15.6 inch')));
        $this->at(3, fn () => $track->wishlistAdd($this->visitor(6), $this->p('LG 55 Inch UHD Smart TV')));
    }

    private function searches(): void
    {
        $log = app(SearchLog::class);
        $engine = app(SearchEngine::class);

        // [query, searchers (visitor numbers, or signed-in keys), days ago]
        $queries = [
            ['laptop', [1, 2, 3, 4, 5], 6],
            ['samsung tv', [1, 2, 3, 4], 5],
            ['tent', ['pieter', 'naledi', 'ayesha'], 7],
            ['air fryer', [2, 3, 4], 4],
            ['rooibos', [5, 6, 7], 4],
            ['sleeping bag', [1, 6, 8], 3],
            ['ps5 console', [6, 7], 2],
            ['iphone 15', [8], 2],
            ['standing desk', [2], 1],
        ];

        foreach ($queries as [$query, $who, $daysAgo]) {
            $total = $engine->search(SearchRequest::fromArray(['q' => $query]))->total;

            foreach ($who as $person) {
                $actor = is_int($person) ? $this->visitor($person) : Actor::forUser($this->users[$person]);

                $this->at($daysAgo, fn () => $log->record($actor, $query, $total));
                $this->at($daysAgo, fn () => app(Track::class)->search($actor, $total, $total === 0));
            }
        }
    }

    private function questions(): void
    {
        $service = app(ProductQuestions::class);

        $rows = [
            ['thandi', 'Lenovo IdeaPad Slim 3, 15.6 inch', 'Does it come with Windows 11 installed, or do I have to install it myself?', 'kabelo', 'It ships with Windows 11 Home installed and activated. You only need to sign in and update.', 20],
            ['pieter', 'Alpine 2-Person Dome Tent', 'Is a footprint or groundsheet included in the box?', 'johan', 'No, the footprint is sold separately. The floor is taped and 3000mm rated, so it is fine without one for a night or two.', 30],
            ['sipho', 'Samsung 65 Inch QLED 4K Smart TV', 'Is a wall bracket included, and does the stand fit a 1.2 metre unit?', 'kabelo', 'A bracket is not included. The feet are 1.43 metres apart, so a 1.2 metre unit is too narrow for the supplied stand.', 10],
            ['naledi', 'Samsung 55 Inch Crystal UHD Smart TV', 'Does it have Showmax and DStv Stream, or only Netflix and YouTube?', null, null, 4],
            ['ayesha', 'Air Fryer 5.5L Digital', 'Can the basket go in the dishwasher, and is the coating PFOA free?', null, null, 3],
            ['sipho', 'Samsung Galaxy A15 Smartphone', 'Is the 128GB model dual SIM with a separate memory card slot?', 'kabelo', 'Yes: two nano SIM slots and a separate microSD slot, so you do not have to give up a SIM for storage.', 8],
        ];

        foreach ($rows as [$asker, $name, $body, $answerer, $answer, $daysAgo]) {
            $question = $this->at($daysAgo, fn () => $service->ask($this->users[$asker], $this->p($name), $body));

            if ($answerer !== null) {
                $this->at($daysAgo - 1 >= 0 ? $daysAgo - 1 : 0, fn () => $service->answer($this->users[$answerer], $question, $answer), 14);
            }
        }
    }

    private function collections(): void
    {
        $collections = app(Collections::class);
        $operator = $this->users['operator'];

        $this->at(10, fn () => $collections->create($operator, [
            'title' => 'Back to campus',
            'description' => 'Laptops, a mouse, a stand and a bag that suit a student budget. Hand-picked, not paid for.',
            'type' => Collection::MANUAL,
            'visibility' => 'public',
            'product_ids' => collect(['Lenovo IdeaPad Slim 3, 15.6 inch', 'HP 250 G9 Business Laptop', 'Wireless Mouse', 'Aluminium Laptop Stand', '20L Daypack', 'A5 Dotted Notebook'])->map(fn ($n) => $this->p($n)->id)->all(),
        ]));

        $this->at(8, fn () => $collections->create($operator, [
            'title' => 'Camp season',
            'description' => 'Everything for camping and hiking that is in stock right now, cheapest first.',
            'type' => Collection::DYNAMIC,
            'visibility' => 'public',
            'rule' => ['category' => 'camping-hiking', 'in_stock' => true, 'sort' => 'price_asc'],
        ]));

        $this->at(6, fn () => $collections->create($operator, [
            'title' => 'Gifts under R500',
            'description' => 'Things you can wrap and hand over, all in stock.',
            'type' => Collection::DYNAMIC,
            'visibility' => 'public',
            'rule' => ['price_max' => 500, 'in_stock' => true, 'sort' => 'newest'],
        ]));

        $this->at(2, fn () => $collections->create($operator, [
            'title' => 'Winter warmers',
            'description' => 'A draft: not shown to shoppers yet.',
            'type' => Collection::MANUAL,
            'visibility' => 'draft',
            'product_ids' => [$this->p('Cotton Throw Blanket, Sand')->id, $this->p('Fleece Pullover, Half Zip')->id],
        ]));
    }

    private function preferences(): void
    {
        // Naledi opted in to e-mail for alerts; everyone else keeps the defaults (alerts in the app, marketing off).
        app(NotificationPreferences::class)->set($this->users['naledi'], NotificationPreferences::ALERTS, mail: true, inApp: true);
    }

    private function abandonedCarts(): void
    {
        $cart = app(CartManager::class);

        $this->at(1, function () use ($cart) {
            $cart->add($this->users['thandi'], ProductVariant::query()->where('product_id', $this->p('Air Fryer 5.5L Digital')->id)->firstOrFail(), 1);
            $cart->add($this->users['thandi'], ProductVariant::query()->where('product_id', $this->p('Braai Tongs, Stainless Steel')->id)->firstOrFail(), 2);
        });
    }
}
