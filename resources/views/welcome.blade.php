<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Dot.Emall — Open a store. Shop every store.</title>
        <meta name="description" content="A multi-vendor marketplace: sellers open a store, list products, and fulfil orders, while buyers shop across every store on the platform.">

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,500;12..96,600;12..96,700;12..96,800&family=Hanken+Grotesk:wght@400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <style>
            :root {
                --paper: #f5f4ef;
                --panel: #fdfcf9;
                --ink: #1c2036;
                --ink-soft: #565a72;
                --gold: #c99a2e;
                --gold-bright: #eec13f;
                --indigo: #4a52a0;
                --indigo-soft: #6b74c4;
                --line: rgba(28, 32, 54, 0.12);
                --font-display: 'Bricolage Grotesque', ui-sans-serif, sans-serif;
                --font-body: 'Hanken Grotesk', system-ui, sans-serif;
                --font-mono: 'DM Mono', ui-monospace, monospace;
                --ease-out: cubic-bezier(0.23, 1, 0.32, 1);
            }
            html { background: var(--paper); }
            body { font-family: var(--font-body); background: var(--paper); color: var(--ink); }
            .font-display { font-family: var(--font-display); }
            .font-mono { font-family: var(--font-mono); }

            .press { transition: transform 160ms var(--ease-out); }
            .press:active { transform: scale(0.97); }

            @media (prefers-reduced-motion: no-preference) {
                .reveal {
                    opacity: 0;
                    transform: translateY(14px);
                    transition: opacity 600ms var(--ease-out), transform 600ms var(--ease-out);
                }
                .reveal.is-visible { opacity: 1; transform: translateY(0); }
                .draw-path {
                    stroke-dasharray: 700;
                    stroke-dashoffset: 700;
                    transition: stroke-dashoffset 1200ms var(--ease-out);
                }
                .draw-path.is-visible { stroke-dashoffset: 0; }
            }
            @media (prefers-reduced-motion: reduce) {
                .reveal { opacity: 1; transform: none; }
                .draw-path { stroke-dashoffset: 0; }
            }

            @media (hover: hover) and (pointer: fine) {
                .row-hover:hover { background: rgba(28, 32, 54, 0.025); }
                .link-underline { background-size: 0% 1px; }
                .link-underline:hover { background-size: 100% 1px; }
            }
            .link-underline {
                background-image: linear-gradient(currentColor, currentColor);
                background-position: 0 100%;
                background-repeat: no-repeat;
                transition: background-size 220ms var(--ease-out);
            }
        </style>
    </head>
    <body class="antialiased">

        <!-- Nav -->
        <header
            x-data="{ scrolled: false, mobileMenuOpen: false }"
            @scroll.window="scrolled = window.pageYOffset > 24"
            :class="scrolled ? 'bg-[#f5f4ef]/95 backdrop-blur-md border-b border-[var(--line)]' : 'border-b border-transparent'"
            class="fixed top-0 left-0 right-0 z-50 transition-colors duration-300"
        >
            <nav class="max-w-[1400px] mx-auto px-5 sm:px-8 py-3 flex items-center justify-between">
                <a href="/" class="flex items-center press">
                    <img src="{{ asset('images/logo.png') }}" alt="Dot.Emall" class="h-14 sm:h-[4.5rem] w-auto">
                </a>

                <div class="hidden md:flex items-center gap-8 font-mono text-[13px] tracking-wide uppercase text-[var(--ink-soft)]">
                    <a href="#sellers" class="link-underline hover:text-[var(--ink)] pb-0.5">Sell</a>
                    <a href="#buyers" class="link-underline hover:text-[var(--ink)] pb-0.5">Shop</a>
                </div>

                @if (Route::has('login'))
                    <div class="flex items-center gap-3">
                        @auth
                            <a href="{{ url('/dashboard') }}" class="press flex items-center gap-2 px-5 py-2.5 bg-[var(--ink)] hover:bg-[var(--indigo)] text-white text-sm font-display font-semibold rounded-full transition-colors">
                                Dashboard
                            </a>
                        @else
                            <a href="{{ route('login') }}" class="hidden sm:block text-sm font-medium text-[var(--ink-soft)] hover:text-[var(--ink)] transition-colors">
                                Sign in
                            </a>
                            @if (Route::has('register'))
                                <a href="{{ route('register') }}" class="press px-5 py-2.5 bg-[var(--ink)] hover:bg-[var(--indigo)] text-white text-sm font-display font-semibold rounded-full transition-colors">
                                    Create account
                                </a>
                            @endif
                        @endauth

                        <button @click="mobileMenuOpen = !mobileMenuOpen" class="md:hidden press p-2 -mr-2 text-[var(--ink)]" aria-label="Toggle menu">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path x-show="!mobileMenuOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M4 7h16M4 12h16M4 17h16"></path>
                                <path x-show="mobileMenuOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                @endif
            </nav>

            <div x-show="mobileMenuOpen"
                 x-transition:enter="transition ease-out duration-150"
                 x-transition:enter-start="opacity-0"
                 x-transition:enter-end="opacity-100"
                 x-transition:leave="transition ease-in duration-100"
                 x-transition:leave-start="opacity-100"
                 x-transition:leave-end="opacity-0"
                 class="md:hidden border-t border-[var(--line)] bg-[var(--paper)]"
                 style="display: none;">
                <div class="flex flex-col px-5 py-4 gap-1 font-mono text-sm uppercase tracking-wide">
                    <a href="#sellers" class="px-3 py-2.5 text-[var(--ink-soft)] hover:text-[var(--ink)]">Sell</a>
                    <a href="#buyers" class="px-3 py-2.5 text-[var(--ink-soft)] hover:text-[var(--ink)]">Shop</a>
                    @guest
                        <a href="{{ route('login') }}" class="px-3 py-2.5 text-[var(--ink-soft)] hover:text-[var(--ink)]">Sign in</a>
                    @endguest
                </div>
            </div>
        </header>

        <!-- Hero -->
        <section class="relative pt-32 pb-16 sm:pb-24 px-5 sm:px-8 overflow-hidden">
            <!-- Photo: cardboard boxes and packaging supplies in a warehouse, by Guilherme Mendes (@guilhermemendes), unsplash.com/photos/cardboard-boxes-and-packaging-supplies-in-a-warehouse-RVP3wAw9gJY -->
            <div class="absolute inset-0 bg-cover bg-center" style="background-image: url('https://images.unsplash.com/photo-1685483749753-0dab7e144794?q=80&w=2400&auto=format&fit=crop');"></div>
            <div class="absolute inset-0" style="background: linear-gradient(100deg, rgba(28,32,54,0.92) 0%, rgba(28,32,54,0.80) 32%, rgba(28,32,54,0.45) 55%, rgba(28,32,54,0.18) 75%, rgba(28,32,54,0.02) 92%);"></div>
            <div class="absolute inset-0" style="background: linear-gradient(180deg, rgba(28,32,54,0) 0%, rgba(28,32,54,0.10) 55%, rgba(28,32,54,0.35) 80%, #f5f4ef 100%);"></div>
            <div class="relative z-10 max-w-[1400px] mx-auto">
                <div class="grid lg:grid-cols-[1.05fr_0.95fr] gap-14 lg:gap-10 items-center">
                    <div class="reveal" data-reveal>
                        <p class="font-mono text-xs tracking-[0.18em] uppercase text-[var(--gold)] mb-6">
                            Multi-vendor marketplace
                        </p>
                        <h1 class="font-display font-semibold text-4xl sm:text-5xl lg:text-6xl leading-[1.05] tracking-tight text-[var(--paper)] mb-6">
                            Open a store.<br>Shop every store.
                        </h1>
                        <p class="text-lg text-[var(--paper)] leading-relaxed max-w-xl mb-10">
                            Dot.Emall is the marketplace platform in the Dot Ecosystem: sellers open a store, list products, and fulfil orders, while buyers shop across every store on the platform from one cart and one account.
                        </p>

                        @guest
                            <div class="flex flex-wrap items-center gap-4">
                                <a href="{{ route('register') }}" class="press px-7 py-3.5 bg-[var(--ink)] hover:bg-[var(--indigo)] text-white font-display font-semibold rounded-full transition-colors">
                                    Open a store
                                </a>
                                <a href="#buyers" class="press flex items-center gap-2 px-7 py-3.5 text-[var(--paper)] font-medium rounded-full border border-[rgba(245,244,239,0.35)] hover:border-[var(--indigo-soft)] transition-colors">
                                    Browse the marketplace
                                </a>
                            </div>
                        @endguest
                    </div>

                    <!-- Signature element: the storefront-awning icon from the real logo mark, redrawn as hero line art -->
                    <div class="reveal" data-reveal>
                        <div class="relative rounded-[2rem] border border-[var(--line)] bg-[var(--panel)] p-6 sm:p-8 shadow-[0_30px_60px_-30px_rgba(28,32,54,0.25)]">
                            <div class="flex items-center justify-between mb-6 font-mono text-[11px] tracking-[0.12em] uppercase text-[var(--ink-soft)]">
                                <span>Storefront</span>
                                <span>Open</span>
                            </div>

                            <svg viewBox="0 0 400 280" fill="none" xmlns="http://www.w3.org/2000/svg" class="w-full h-auto" aria-hidden="true">
                                <rect x="0.5" y="0.5" width="399" height="279" rx="14" stroke="var(--line)" stroke-dasharray="4 4"/>
                                <!-- awning -->
                                <path class="draw-path" data-reveal d="M110 110 L120 75 L155 75 L160 110 M160 110 L165 75 L200 75 L205 110 M205 110 L210 75 L245 75 L250 110 M250 110 L255 75 L290 75 L300 110"
                                    stroke="var(--indigo)" stroke-width="2.5" stroke-linejoin="round"/>
                                <path d="M110 110 A25 15 0 0 0 160 110" stroke="var(--gold-bright)" stroke-width="2.5" fill="none"/>
                                <path d="M160 110 A25 15 0 0 0 205 110" stroke="var(--indigo)" stroke-width="2.5" fill="none"/>
                                <path d="M205 110 A22.5 15 0 0 0 250 110" stroke="var(--gold-bright)" stroke-width="2.5" fill="none"/>
                                <path d="M250 110 A25 15 0 0 0 300 110" stroke="var(--indigo)" stroke-width="2.5" fill="none"/>
                                <!-- shopfront body -->
                                <rect x="110" y="110" width="190" height="115" stroke="var(--ink)" stroke-width="2.5"/>
                                <rect x="180" y="160" width="50" height="65" stroke="var(--ink)" stroke-width="2.5"/>
                                <rect x="130" y="150" width="30" height="30" fill="var(--gold-bright)"/>
                                <rect x="250" y="150" width="30" height="30" fill="var(--gold-bright)" opacity="0.5"/>
                            </svg>

                            <div class="flex items-center justify-between mt-6 pt-6 border-t border-[var(--line)]">
                                <span class="font-mono text-[11px] tracking-wide text-[var(--ink-soft)]">Order #2291</span>
                                <span class="font-mono text-[11px] tracking-wide text-[var(--indigo)] font-medium">Pending</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Two sides of the marketplace -->
        <section class="py-24 sm:py-28 px-5 sm:px-8">
            <div class="max-w-[1400px] mx-auto">
                <div class="max-w-2xl mb-16 reveal" data-reveal>
                    <p class="font-mono text-xs tracking-[0.18em] uppercase text-[var(--gold)] mb-4">A two-sided marketplace</p>
                    <h2 class="font-display font-semibold text-3xl sm:text-4xl text-[var(--ink)] leading-tight">
                        Not a single storefront. Every storefront.
                    </h2>
                </div>

                <div class="grid lg:grid-cols-2 gap-x-16">
                    <div id="sellers">
                        <h3 class="font-display font-semibold text-xl text-[var(--ink)] mb-1">For sellers</h3>
                        <p class="text-sm text-[var(--ink-soft)] mb-6">Your store, your catalogue, your orders.</p>
                        <div class="border-t border-[var(--line)]">
                            @php
                                $sellerItems = [
                                    ['tag' => 'Store', 'title' => 'Your own storefront', 'body' => 'A named, sluggable store you own — activate or deactivate it, and every product you list belongs to it.'],
                                    ['tag' => 'Catalogue', 'title' => 'Products & categories', 'body' => 'Price, compare-at price, stock, and SKU per product, an ordered image gallery, and a self-referential category tree.'],
                                    ['tag' => 'Orders', 'title' => 'Owner dashboard', 'body' => 'Total products, total orders, revenue from delivered orders, pending-order count, and your eight most recent orders.'],
                                    ['tag' => 'Reviews', 'title' => 'Ratings on your listings', 'body' => 'One review per buyer per product — feedback attached directly to the item, not buried in a support inbox.'],
                                ];
                            @endphp
                            @foreach ($sellerItems as $item)
                                <div class="row-hover border-b border-[var(--line)] px-1 py-6 transition-colors reveal" data-reveal>
                                    <p class="font-mono text-[11px] tracking-[0.14em] uppercase text-[var(--indigo)] mb-2">{{ $item['tag'] }}</p>
                                    <h4 class="font-display font-semibold text-lg text-[var(--ink)] mb-1.5">{{ $item['title'] }}</h4>
                                    <p class="text-[var(--ink-soft)] leading-relaxed text-sm">{{ $item['body'] }}</p>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div id="buyers" class="mt-14 lg:mt-0">
                        <h3 class="font-display font-semibold text-xl text-[var(--ink)] mb-1">For buyers</h3>
                        <p class="text-sm text-[var(--ink-soft)] mb-6">One cart, every store on the platform.</p>
                        <div class="border-t border-[var(--line)]">
                            @php
                                $buyerItems = [
                                    ['tag' => 'Browse', 'title' => 'Shop across every store', 'body' => 'No need to check store by store — products from every seller sit in one catalogue you can browse and search.'],
                                    ['tag' => 'Cart', 'title' => 'One cart, many sellers', 'body' => 'Add items from different stores to a single cart; your cart is yours alone, never visible to another buyer.'],
                                    ['tag' => 'Checkout', 'title' => 'One order, itemized', 'body' => 'Checkout creates an order with line items per product, subtotal, total, and your shipping address.'],
                                    ['tag' => 'Reviews', 'title' => 'Rate what you bought', 'body' => 'Leave one rating and a written review per product — help the next buyer decide.'],
                                ];
                            @endphp
                            @foreach ($buyerItems as $item)
                                <div class="row-hover border-b border-[var(--line)] px-1 py-6 transition-colors reveal" data-reveal>
                                    <p class="font-mono text-[11px] tracking-[0.14em] uppercase text-[var(--gold)] mb-2">{{ $item['tag'] }}</p>
                                    <h4 class="font-display font-semibold text-lg text-[var(--ink)] mb-1.5">{{ $item['title'] }}</h4>
                                    <p class="text-[var(--ink-soft)] leading-relaxed text-sm">{{ $item['body'] }}</p>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Ecosystem -->
        <section class="py-24 sm:py-28 px-5 sm:px-8 bg-[var(--ink)]">
            <div class="max-w-[1400px] mx-auto">
                <div class="grid lg:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)] gap-12 lg:gap-20">
                    <div class="reveal" data-reveal>
                        <p class="font-mono text-xs tracking-[0.18em] uppercase text-[var(--gold-bright)] mb-4">Part of the Dot Ecosystem</p>
                        <h2 class="font-display font-semibold text-3xl sm:text-4xl text-white leading-tight mb-5">
                            The second platform to publish a signed activity pack
                        </h2>
                        <p class="text-[#c6c7d6] leading-relaxed max-w-sm">
                            Dot.Emall published its first real, signed Knowledge Pack to Dot.Brain on 2026-08-02 — aggregate demand and fulfilment signals, never individual buyer behavior.
                        </p>
                    </div>

                    <div class="grid sm:grid-cols-2 gap-x-10">
                        @php
                            $capabilities = [
                                ['title' => 'Team-based accounts', 'body' => 'Jetstream teams and Sanctum auth — the same account model as every other Dot platform.'],
                                ['title' => 'Ecosystem single sign-on', 'body' => 'A one-time Sanctum token minted elsewhere in the ecosystem logs you straight into your dashboard.'],
                                ['title' => 'Order events', 'body' => 'Checkout dispatches a real OrderPlaced event the moment your order transaction commits.'],
                                ['title' => 'Shared PostgreSQL', 'body' => 'Store, product, order, and review records run on the same infrastructure as the rest of the Dot Ecosystem.'],
                            ];
                        @endphp
                        @foreach ($capabilities as $c)
                            <div class="py-6 border-t border-[rgba(255,255,255,0.14)] reveal" data-reveal>
                                <h3 class="font-display font-medium text-base text-white mb-1.5">{{ $c['title'] }}</h3>
                                <p class="text-sm text-[#c6c7d6] leading-relaxed">{{ $c['body'] }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </section>

        <!-- CTA -->
        <section class="relative py-28 sm:py-36 px-5 sm:px-8 overflow-hidden">
            <!-- Photo: warehouse shelves full of boxes, by Brayden Prato (@pratophoto), unsplash.com/photos/a-warehouse-filled-with-lots-of-shelves-filled-with-boxes-If5vloAJSBQ -->
            <div class="absolute inset-0 bg-cover bg-center" style="background-image: url('https://images.unsplash.com/photo-1769355104335-acef3aa4c9b6?q=80&w=2400&auto=format&fit=crop');"></div>
            <div class="absolute inset-0" style="background: linear-gradient(180deg, #f5f4ef 0%, rgba(28,32,54,0.80) 20%, rgba(28,32,54,0.85) 50%, rgba(28,32,54,0.80) 80%, #f5f4ef 100%);"></div>
            <div class="relative z-10 max-w-2xl mx-auto text-center reveal" data-reveal>
                <h2 class="font-display font-semibold text-3xl sm:text-4xl text-[var(--paper)] leading-tight mb-5">
                    Your store, or your next order — either way, start here
                </h2>
                <p class="text-[var(--paper)] leading-relaxed mb-10 max-w-lg mx-auto">
                    Sign in with your Dot Ecosystem account or create one to open a store or start shopping.
                </p>

                @guest
                    <div class="flex flex-wrap justify-center gap-4">
                        <a href="{{ route('register') }}" class="press px-8 py-3.5 bg-[var(--ink)] hover:bg-[var(--indigo)] text-white font-display font-semibold rounded-full transition-colors">
                            Create account
                        </a>
                        <a href="{{ route('login') }}" class="press px-8 py-3.5 text-[var(--paper)] font-medium rounded-full border border-[rgba(245,244,239,0.35)] hover:border-[var(--indigo-soft)] transition-colors">
                            Sign in
                        </a>
                    </div>
                @endguest
            </div>
        </section>

        <!-- Footer -->
        <footer class="py-14 px-5 sm:px-8 border-t border-[var(--line)]">
            <div class="max-w-[1400px] mx-auto flex flex-col sm:flex-row items-center justify-between gap-6">
                <a href="/" class="flex items-center">
                    <img src="{{ asset('images/logo.png') }}" alt="Dot.Emall" class="h-11 w-auto opacity-90">
                </a>
                <div class="flex items-center gap-6 font-mono text-xs tracking-wide uppercase text-[var(--ink-soft)]">
                    <a href="{{ route('policy.show') }}" class="hover:text-[var(--ink)] transition-colors">Privacy</a>
                    <a href="{{ route('cookies') }}" class="hover:text-[var(--ink)] transition-colors">Cookies</a>
                    <a href="{{ route('terms.show') }}" class="hover:text-[var(--ink)] transition-colors">Terms</a>
                </div>
                <p class="font-mono text-xs tracking-wide text-[var(--ink-soft)]">
                    &copy; {{ date('Y') }} Dot.Emall. Multi-vendor marketplace for the Dot Ecosystem.
                </p>
            </div>
        </footer>

        <script>
            if (window.matchMedia('(prefers-reduced-motion: no-preference)').matches && 'IntersectionObserver' in window) {
                const io = new IntersectionObserver((entries) => {
                    entries.forEach((entry) => {
                        if (entry.isIntersecting) {
                            entry.target.classList.add('is-visible');
                            io.unobserve(entry.target);
                        }
                    });
                }, { threshold: 0.15, rootMargin: '0px 0px -40px 0px' });
                document.querySelectorAll('[data-reveal]').forEach((el) => io.observe(el));
            } else {
                document.querySelectorAll('[data-reveal]').forEach((el) => el.classList.add('is-visible'));
            }
        </script>
    </body>
</html>
