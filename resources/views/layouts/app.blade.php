<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Dot.Emall</title>
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@600;700;800&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { darkMode: 'class', corePlugins: { preflight: false } }</script>
    <script>
        // Dark mode: this dashboard's own markup is intentionally fixed-dark (inline styles,
        // not Tailwind dark: classes), so this toggle mainly affects Jetstream-rendered pages
        // (Profile, Team Settings) which do use dark: classes. Kept for theme consistency
        // ecosystem-wide and so those subpages respect the user's light/dark preference.
        (function () {
            const stored = localStorage.getItem('dot-theme');
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            if (stored === 'dark' || (!stored && prefersDark)) {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>
    <style>
        :root { --accent: #f97316; --accent-rgb: 249,115,22; }
        *, *::before, *::after { box-sizing: border-box; }
        body { margin:0; background:#09090b; color:#f4f4f5; font-family:'Inter',system-ui,sans-serif; font-size:14px; line-height:1.5; }
        .material-symbols-rounded { font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24; line-height:1; user-select:none; }
        [x-cloak] { display:none!important; }

        /* Sidebar */
        .sidebar { position:fixed; left:0; top:0; width:260px; height:100vh; background:#0d0d10; border-right:1px solid rgba(255,255,255,0.06); display:flex; flex-direction:column; z-index:40; overflow:hidden; }
        .sidebar::before { content:''; position:absolute; top:-80px; left:-80px; width:320px; height:320px; background:radial-gradient(circle, rgba(249,115,22,0.1) 0%, transparent 65%); pointer-events:none; }

        .sidebar-brand { padding:20px 18px 14px; display:flex; align-items:center; gap:11px; flex-shrink:0; }
        .brand-icon { width:36px; height:36px; border-radius:10px; background:rgba(249,115,22,0.12); border:1px solid rgba(249,115,22,0.22); display:flex; align-items:center; justify-content:center; flex-shrink:0; }
        .brand-icon .material-symbols-rounded { font-size:18px; color:#f97316; }
        .brand-name { font-family:'Syne',sans-serif; font-size:14.5px; font-weight:700; color:#f4f4f5; letter-spacing:-0.01em; line-height:1.2; }
        .brand-status { display:flex; align-items:center; gap:5px; margin-top:3px; }
        .live-dot { width:6px; height:6px; border-radius:50%; background:#f97316; flex-shrink:0; animation:live-pulse 2.8s ease-in-out infinite; }
        @keyframes live-pulse { 0%,100% { opacity:1; box-shadow:0 0 0 0 rgba(249,115,22,0.45); } 60% { opacity:.6; box-shadow:0 0 0 5px rgba(249,115,22,0); } }
        .brand-subtitle { font-size:10px; font-weight:500; color:#3f3f46; text-transform:uppercase; letter-spacing:0.09em; }

        .sidebar-divider { height:1px; background:rgba(255,255,255,0.06); margin:4px 14px 8px; }
        .sidebar-nav { padding:0 10px; flex:1; overflow-y:auto; scrollbar-width:none; }
        .sidebar-nav::-webkit-scrollbar { display:none; }
        .nav-section-label { font-size:10px; font-weight:600; color:#3f3f46; text-transform:uppercase; letter-spacing:0.1em; padding:14px 8px 5px; }
        .nav-item { display:flex; align-items:center; gap:9px; padding:7.5px 10px; border-radius:8px; font-size:13px; font-weight:500; color:#71717a; text-decoration:none; transition:background .13s,color .13s,transform .13s; margin-bottom:1px; }
        .nav-item:hover { background:rgba(255,255,255,0.05); color:#d4d4d8; transform:translateX(1px); }
        .nav-item.active { background:rgba(249,115,22,0.1); color:#f97316; font-weight:600; }
        .nav-icon { font-size:17px; width:20px; text-align:center; flex-shrink:0; }

        .sidebar-footer { padding:10px 14px 14px; border-top:1px solid rgba(255,255,255,0.06); flex-shrink:0; }
        .user-row { display:flex; align-items:center; gap:9px; padding:8px 6px; border-radius:8px; }
        .user-avatar { width:28px; height:28px; border-radius:50%; background:rgba(249,115,22,0.18); border:1px solid rgba(249,115,22,0.28); display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:700; color:#f97316; flex-shrink:0; font-family:'Syne',sans-serif; }
        .user-name { font-size:12px; font-weight:600; color:#d4d4d8; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .user-team { font-size:10px; color:#52525b; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }

        /* Topbar */
        .topbar { position:fixed; top:0; left:260px; right:0; height:54px; background:rgba(9,9,11,0.85); backdrop-filter:blur(14px); -webkit-backdrop-filter:blur(14px); border-bottom:1px solid rgba(255,255,255,0.06); display:flex; align-items:center; padding:0 22px; z-index:30; gap:12px; }
        .topbar-title { font-family:'Syne',sans-serif; font-size:14px; font-weight:700; color:#f4f4f5; flex:1; }
        .topbar-team { font-size:11px; color:#52525b; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.07); border-radius:6px; padding:3px 8px; font-weight:500; white-space:nowrap; }
        .topbar-btn { width:30px; height:30px; border-radius:7px; border:1px solid rgba(255,255,255,0.08); background:rgba(255,255,255,0.04); display:flex; align-items:center; justify-content:center; color:#71717a; cursor:pointer; transition:background .13s,color .13s; text-decoration:none; flex-shrink:0; }
        .topbar-btn:hover { background:rgba(255,255,255,0.09); color:#d4d4d8; }
        .topbar-btn .material-symbols-rounded { font-size:17px; }

        /* Content */
        .content-wrap { margin-left:260px; padding-top:54px; min-height:100vh; }

        /* Shared UI tokens */
        .dot-card { background:#141416; border:1px solid rgba(255,255,255,0.07); border-radius:12px; }
        .dot-card:hover { border-color:rgba(255,255,255,0.11); }
        .metric-val { font-family:'JetBrains Mono',monospace; font-weight:500; letter-spacing:-0.02em; }
        .dot-input { background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.08); border-radius:8px; color:#f4f4f5; font-family:'Inter',sans-serif; font-size:13px; padding:8px 12px; width:100%; transition:border-color .15s,box-shadow .15s; outline:none; }
        .dot-input:focus { border-color:rgba(249,115,22,0.45); box-shadow:0 0 0 3px rgba(249,115,22,0.07); }
        .dot-input::placeholder { color:#3f3f46; }
        .dot-btn { display:inline-flex; align-items:center; gap:6px; padding:7px 14px; border-radius:8px; font-size:13px; font-weight:600; cursor:pointer; transition:all .14s; border:none; text-decoration:none; font-family:'Inter',sans-serif; }
        .dot-btn-primary { background:#f97316; color:#09090b; }
        .dot-btn-primary:hover { filter:brightness(1.1); }
        .dot-btn-ghost { background:rgba(255,255,255,0.06); color:#a1a1aa; border:1px solid rgba(255,255,255,0.08); }
        .dot-btn-ghost:hover { background:rgba(255,255,255,0.1); color:#f4f4f5; }
        .dot-badge { display:inline-flex; align-items:center; padding:2px 8px; border-radius:100px; font-size:11px; font-weight:600; }
        .dot-badge-accent { background:rgba(249,115,22,0.12); color:#f97316; }
        select.dot-input option { background:#1a1a1f; }

        /* Responsive shell. At 860px and wider nothing below applies except the focus rings
           and the team switcher. Under 860px the sidebar becomes an off-canvas drawer opened
           from the top bar (script: layouts/partials/shell-script.blade.php). */
        .shell-menu-btn, .shell-close, .shell-backdrop { display:none; }
        .shell-menu-btn, .shell-close { align-items:center; justify-content:center; gap:6px; min-width:44px; min-height:44px; border-radius:10px; border:1px solid rgba(255,255,255,0.1); background:rgba(255,255,255,0.05); color:#d4d4d8; font-family:'Inter',sans-serif; font-size:13px; font-weight:600; cursor:pointer; transition:background .13s,border-color .13s; flex-shrink:0; }
        .shell-menu-btn { padding:0 14px 0 10px; }
        .shell-menu-btn:hover, .shell-close:hover { background:rgba(255,255,255,0.1); }
        .shell-menu-btn .material-symbols-rounded, .shell-close .material-symbols-rounded { font-size:22px; }
        .shell-menu-btn:focus-visible, .shell-close:focus-visible, .nav-item:focus-visible, .team-switch summary:focus-visible, .topbar-btn:focus-visible { outline:2px solid var(--accent); outline-offset:2px; }

        /* Team switcher (sidebar footer) */
        .team-switch { margin-top:2px; }
        .team-switch summary { display:flex; align-items:center; gap:9px; padding:7.5px 6px; border-radius:8px; font-size:12px; font-weight:500; color:#71717a; cursor:pointer; list-style:none; }
        .team-switch summary::-webkit-details-marker { display:none; }
        .team-switch summary:hover { background:rgba(255,255,255,0.05); color:#d4d4d8; }
        .team-switch summary .team-switch-name { flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .team-switch[open] summary .team-switch-chevron { transform:rotate(180deg); }
        .team-switch-list { padding:2px 0 4px; max-height:40vh; overflow-y:auto; }
        .team-switch-list form { margin:0; }
        .team-switch-list .nav-item { width:100%; border:none; background:none; cursor:pointer; font-family:'Inter',sans-serif; text-align:left; }
        .team-switch-list .nav-item.active { background:rgba(var(--accent-rgb),0.1); color:var(--accent); }
        .team-switch-list .nav-section-label { padding:8px 10px 4px; }

        /* Notification bell panel */
        .topbar-bell { position:relative; }
        .notif-panel { position:absolute; top:calc(100% + 8px); right:0; width:320px; max-height:420px; overflow-y:auto; background:#141416; border:1px solid rgba(255,255,255,0.09); border-radius:10px; box-shadow:0 12px 32px rgba(0,0,0,0.45); z-index:50; }

        @media (max-width: 859.98px) {
            html.shell-open, html.shell-open body { overflow:hidden; }
            .shell-menu-btn { display:inline-flex; }
            .shell-close { display:inline-flex; margin-left:auto; }

            .sidebar { top:0; bottom:0; height:auto; width:min(300px, 86vw); z-index:60; transform:translateX(-100%); visibility:hidden; overscroll-behavior:contain; transition:transform .22s ease, visibility 0s linear .22s; }
            html.shell-open .sidebar { transform:none; visibility:visible; box-shadow:0 0 48px rgba(0,0,0,0.7); transition:transform .22s ease, visibility 0s; }
            .sidebar-footer { max-height:55%; overflow-y:auto; }
            .nav-item { min-height:44px; }
            .team-switch summary { min-height:44px; }
            .shell-backdrop { display:block; position:fixed; inset:0; z-index:50; background:rgba(0,0,0,0.62); opacity:0; visibility:hidden; transition:opacity .22s ease, visibility 0s linear .22s; }
            html.shell-open .shell-backdrop { opacity:1; visibility:visible; transition:opacity .22s ease, visibility 0s; }

            .topbar { left:0; padding:0 10px; gap:8px; }
            .topbar-title { min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
            .topbar-title h1, .topbar-title h2 { font-size:1rem; line-height:1.3; margin:0; }
            .topbar-team { display:none; }
            .topbar-btn { width:44px; height:44px; border-radius:10px; }
            .topbar-btn .material-symbols-rounded { font-size:20px; }
            .topbar-profile { display:none; }
            .content-wrap { margin-left:0; min-width:0; }
            .shell-legacy-nav { display:none; }

            /* Page content written with inline styles: keep it inside the phone viewport */
            main [style*="padding:2rem 2.5rem"], main [style*="padding: 2rem 2.5rem"] { padding:1.25rem 1rem 2rem !important; }
            main { min-width:0; }
            /* Touch: 44px targets, and 16px text in fields so iOS Safari does not zoom the page on focus */
            main .dot-btn, main .t-btn, main button[type="submit"] { min-height:44px; }
            main a.dot-badge { min-height:44px; display:inline-flex; align-items:center; padding:0 .9rem; }
            main input:not([type="checkbox"]):not([type="radio"]):not([type="file"]):not([type="hidden"]), main select, main textarea { min-height:44px; font-size:16px !important; }
            .topbar .dot-btn { min-height:44px; }

            .notif-panel { position:absolute; left:8px; right:8px; width:auto; top:calc(100% + 6px); max-height:calc(100vh - 80px); }
            .topbar-bell { position:static; }
        }
        @media (max-width: 639.98px) {
            main [style*="grid-template-columns"] { grid-template-columns:minmax(0,1fr) !important; }
            main [style*="grid-template-columns"] > [style*="grid-column"] { grid-column:1 / -1 !important; }
            main .readout-grid { grid-template-columns:repeat(2,minmax(0,1fr)) !important; }
            main .product-grid { grid-template-columns:repeat(2,minmax(0,1fr)) !important; gap:.75rem !important; }
            main .product-grid > a { padding:.7rem !important; }
            main .product-grid > a > div:last-child { flex-wrap:wrap; gap:.15rem .5rem; }
            main .product-grid > a > div[style*="nowrap"] { white-space:normal !important; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; }
        }
        .table-scroll { overflow-x:auto; overscroll-behavior-x:contain; -webkit-overflow-scrolling:touch; }
        .table-scroll:focus-visible { outline:2px solid var(--accent); outline-offset:-2px; }
        @media (min-width: 640px) and (max-width: 859.98px) {
            .topbar-team { display:block; max-width:160px; overflow:hidden; text-overflow:ellipsis; }
        }
        @media (prefers-reduced-motion: reduce) {
            .sidebar, .shell-backdrop, .nav-item, .shell-menu-btn, .shell-close, .topbar-btn { transition:none !important; }
            .live-dot, .dot-spin { animation:none !important; }
            .team-switch[open] summary .team-switch-chevron { transform:none; }
        }
    </style>
    @livewireStyles
    <script defer src="https://unpkg.com/alpinejs@3.10.2/dist/cdn.min.js"></script>
</head>
<body>
    <aside class="sidebar" id="app-sidebar" aria-label="Main navigation">
        <div class="sidebar-brand">
            <div class="brand-icon">
                <span class="material-symbols-rounded">storefront</span>
            </div>
            <div>
                <div class="brand-name">Dot.Emall</div>
                <div class="brand-status">
                    <div class="live-dot"></div>
                    <span class="brand-subtitle">E-Commerce</span>
                </div>
            </div>
            <button type="button" class="shell-close" data-shell-close aria-label="Close menu">
                <span class="material-symbols-rounded" aria-hidden="true">close</span>
            </button>
        </div>

        <div class="sidebar-divider"></div>

        <nav class="sidebar-nav">
            @auth
            <a href="{{ route('dashboard') }}" class="nav-item {{ request()->routeIs('dashboard') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">dashboard</span>
                Dashboard
            </a>
            @endauth
            <a href="{{ route('products.index') }}" class="nav-item {{ request()->routeIs('products.*') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">storefront</span>
                Browse Products
            </a>
            @auth
            <a href="{{ route('cart.index') }}" class="nav-item {{ request()->routeIs('cart.*') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">shopping_cart</span>
                Cart
            </a>
            <a href="{{ route('orders.index') }}" class="nav-item {{ request()->routeIs('orders.*') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">receipt_long</span>
                My Orders
            </a>
            @php $sidebarStore = auth()->user()->store; @endphp
            <div class="nav-section-label">Selling</div>
            @if($sidebarStore)
            <a href="{{ route('seller.products.index') }}" class="nav-item {{ request()->routeIs('seller.products.*') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">inventory_2</span>
                My listings
            </a>
            <a href="{{ route('seller.orders.index') }}" class="nav-item {{ request()->routeIs('seller.orders.*', 'seller.order-items.*') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">local_shipping</span>
                Orders to fulfil
            </a>
            <a href="{{ route('seller.promo-codes.index') }}" class="nav-item {{ request()->routeIs('seller.promo-codes.*') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">sell</span>
                Promo codes
            </a>
            <a href="{{ route('seller.store.edit') }}" class="nav-item {{ request()->routeIs('seller.store.edit', 'seller.store.update') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">store</span>
                My store
            </a>
            @else
            <a href="{{ route('seller.store.create') }}" class="nav-item {{ request()->routeIs('seller.store.*') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">add_business</span>
                Become a seller
            </a>
            @endif
            <div class="sidebar-divider" style="margin:10px 0;"></div>
            <a href="{{ route('profile.show') }}" class="nav-item {{ request()->routeIs('profile.show') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">manage_accounts</span>
                Profile & Settings
            </a>
            @else
            <div class="sidebar-divider" style="margin:10px 0;"></div>
            <a href="{{ route('login') }}" class="nav-item">
                <span class="material-symbols-rounded nav-icon">login</span>
                Sign in
            </a>
            <a href="{{ route('register') }}" class="nav-item">
                <span class="material-symbols-rounded nav-icon">person_add</span>
                Create an account
            </a>
            @endauth
        </nav>

        @auth
        <div class="sidebar-footer">
            <div class="user-row">
                <div class="user-avatar">{{ strtoupper(substr(Auth::user()->name, 0, 1)) }}</div>
                <div style="min-width:0;flex:1;">
                    <div class="user-name">{{ Auth::user()->name }}</div>
                    <div class="user-team">{{ Auth::user()->currentTeam->name ?? 'Personal' }}</div>
                </div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="topbar-btn" title="Sign out" aria-label="Sign out">
                        <span class="material-symbols-rounded">logout</span>
                    </button>
                </form>
            </div>
            @if (Laravel\Jetstream\Jetstream::hasTeamFeatures() && Auth::user()->currentTeam)
            <details class="team-switch">
                <summary>
                    <span class="material-symbols-rounded nav-icon" aria-hidden="true">groups</span>
                    <span class="team-switch-name">Teams</span>
                    <span class="material-symbols-rounded team-switch-chevron" aria-hidden="true" style="font-size:18px;">expand_more</span>
                </summary>
                <div class="team-switch-list">
                    <a href="{{ route('teams.show', Auth::user()->currentTeam->id) }}" class="nav-item {{ request()->routeIs('teams.show') ? 'active' : '' }}">Team settings</a>
                    @can('create', Laravel\Jetstream\Jetstream::newTeamModel())
                    <a href="{{ route('teams.create') }}" class="nav-item {{ request()->routeIs('teams.create') ? 'active' : '' }}">Create new team</a>
                    @endcan
                    @if (Auth::user()->allTeams()->count() > 1)
                    <div class="nav-section-label">Switch teams</div>
                    @foreach (Auth::user()->allTeams() as $switchTeam)
                    <form method="POST" action="{{ route('current-team.update') }}">
                        @method('PUT')
                        @csrf
                        <input type="hidden" name="team_id" value="{{ $switchTeam->id }}">
                        <button type="submit" class="nav-item {{ Auth::user()->isCurrentTeam($switchTeam) ? 'active' : '' }}" @if (Auth::user()->isCurrentTeam($switchTeam)) aria-current="true" @endif>{{ $switchTeam->name }}</button>
                    </form>
                    @endforeach
                    @endif
                </div>
            </details>
            @endif
        </div>
        @endauth
    </aside>

    <div class="shell-backdrop" data-shell-close aria-hidden="true"></div>

    <header class="topbar" data-shell-behind>
        <button type="button" class="shell-menu-btn" data-shell-open aria-controls="app-sidebar" aria-expanded="false">
            <span class="material-symbols-rounded" aria-hidden="true">menu</span>
            <span>Menu</span>
        </button>
        <div class="topbar-title">
            @isset($header){{ $header }}@else Dot.Emall
            @endisset
        </div>
        @auth
        <span class="topbar-team">{{ Auth::user()->currentTeam->name ?? 'Personal' }}</span>
        <livewire:notification-bell />
        @endauth
        <button
            type="button"
            onclick="document.documentElement.classList.toggle('dark'); localStorage.setItem('dot-theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light');"
            class="topbar-btn"
            title="Toggle dark mode"
        >
            <span class="material-symbols-rounded">dark_mode</span>
        </button>
        @auth
        <a href="{{ route('profile.show') }}" class="topbar-btn topbar-profile" title="Profile" aria-label="Profile">
            <span class="material-symbols-rounded">account_circle</span>
        </a>
        @else
        <a href="{{ route('login') }}" class="dot-btn dot-btn-primary" style="padding:5px 12px;">Sign in</a>
        @endauth
    </header>

    @auth
    <div class="shell-legacy-nav">
        @livewire('navigation-menu')
    </div>
    @endauth

    <div class="content-wrap" data-shell-behind>
        <x-banner />
        <main id="main-content">{{ $slot }}</main>
    </div>

    @stack('modals')
    @include('layouts.partials.shell-script')
    @livewireScripts
</body>
</html>
