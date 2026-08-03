<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Dot.Emall - Multi-Vendor Marketplace Platform</title>
        <meta name="description" content="Dot.Emall is the marketplace platform in the Dot Ecosystem: sellers open a store, list products, and fulfil orders, while buyers shop across every store in one marketplace.">

        <!-- Favicon -->
        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
        <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
        <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <style>
            @keyframes float {
                0%, 100% { transform: translateY(0px); }
                50% { transform: translateY(-20px); }
            }
            .float-animation {
                animation: float 6s ease-in-out infinite;
            }
            @keyframes slideInUp {
                from {
                    opacity: 0;
                    transform: translateY(30px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }
            .slide-in-up {
                animation: slideInUp 0.8s ease-out forwards;
            }
        </style>
    </head>
    <body class="bg-gray-900 text-gray-100 antialiased">

        <!-- Header -->
        <header class="fixed top-0 left-0 right-0 z-50 transition-all duration-300" x-data="{ scrolled: false, mobileMenuOpen: false }"
                @scroll.window="scrolled = window.pageYOffset > 50"
                :class="scrolled ? 'bg-gray-900/95 backdrop-blur-xl shadow-lg border-b border-gray-800' : 'bg-transparent'">
            <nav class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
                <div class="flex items-center justify-between">
                    <!-- Logo -->
                    <a href="/" class="flex items-center gap-3 group">
                        <div class="relative">
                            <img src="{{ asset('images/logo.png') }}" alt="Dot.Emall" class="h-14 w-auto transform group-hover:scale-105 transition-transform duration-300">
                            <div class="absolute -top-1 -right-1 w-3 h-3 bg-green-400 rounded-full animate-pulse"></div>
                        </div>
                        <p class="hidden sm:block text-xs text-indigo-400 font-medium border-l border-gray-700 pl-3">Multi-Vendor Marketplace</p>
                    </a>

                    <!-- Desktop Navigation -->
                    <div class="hidden md:flex items-center gap-8">
                        <a href="#features" class="text-gray-300 hover:text-indigo-400 transition-colors font-medium">Features</a>
                        <a href="#capabilities" class="text-gray-300 hover:text-indigo-400 transition-colors font-medium">Capabilities</a>
                        <a href="#how-it-works" class="text-gray-300 hover:text-indigo-400 transition-colors font-medium">How It Works</a>
                    </div>

                    <!-- Auth Links -->
                    @if (Route::has('login'))
                        <div class="flex items-center gap-3">
                            @auth
                                <a href="{{ url('/dashboard') }}" class="hidden sm:flex items-center gap-2 px-6 py-2.5 bg-gradient-to-r from-indigo-500 to-indigo-600 hover:from-indigo-600 hover:to-indigo-700 text-white font-semibold rounded-xl transition-all duration-300 shadow-lg shadow-indigo-500/30 transform hover:scale-105">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                                    </svg>
                                    <span>Dashboard</span>
                                </a>
                            @else
                                <a href="{{ route('login') }}" class="hidden sm:block px-4 py-2 text-gray-300 hover:text-white transition-colors font-medium">
                                    Sign In
                                </a>
                                @if (Route::has('register'))
                                    <a href="{{ route('register') }}" class="flex items-center gap-2 px-6 py-2.5 bg-gradient-to-r from-indigo-500 to-indigo-600 hover:from-indigo-600 hover:to-indigo-700 text-white font-semibold rounded-xl transition-all duration-300 shadow-lg shadow-indigo-500/30 transform hover:scale-105">
                                        <span>Get Started</span>
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                                        </svg>
                                    </a>
                                @endif
                            @endauth

                            <!-- Mobile menu button -->
                            <button @click="mobileMenuOpen = !mobileMenuOpen" class="md:hidden p-2 text-gray-400 hover:text-white">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path x-show="!mobileMenuOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                                    <path x-show="mobileMenuOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                </svg>
                            </button>
                        </div>
                    @endif
                </div>

                <!-- Mobile Menu -->
                <div x-show="mobileMenuOpen"
                     x-transition:enter="transition ease-out duration-200"
                     x-transition:enter-start="opacity-0 transform scale-95"
                     x-transition:enter-end="opacity-100 transform scale-100"
                     x-transition:leave="transition ease-in duration-150"
                     x-transition:leave-start="opacity-100 transform scale-100"
                     x-transition:leave-end="opacity-0 transform scale-95"
                     class="md:hidden mt-4 py-4 border-t border-gray-800"
                     style="display: none;">
                    <div class="flex flex-col gap-2">
                        <a href="#features" class="px-4 py-2 text-gray-300 hover:text-indigo-400 hover:bg-gray-800 rounded-lg transition-colors">Features</a>
                        <a href="#capabilities" class="px-4 py-2 text-gray-300 hover:text-indigo-400 hover:bg-gray-800 rounded-lg transition-colors">Capabilities</a>
                        <a href="#how-it-works" class="px-4 py-2 text-gray-300 hover:text-indigo-400 hover:bg-gray-800 rounded-lg transition-colors">How It Works</a>
                        @guest
                            <a href="{{ route('login') }}" class="px-4 py-2 text-gray-300 hover:text-indigo-400 hover:bg-gray-800 rounded-lg transition-colors">Sign In</a>
                        @endguest
                    </div>
                </div>
            </nav>
        </header>

        <!-- Hero Section -->
        <section class="relative min-h-screen flex items-center justify-center overflow-hidden pt-20">
            <!-- Photographic Background: real warehouse-shelves-full-of-boxes photo by Brayden Prato (@pratophoto), unsplash.com/photos/a-warehouse-filled-with-lots-of-shelves-filled-with-boxes-If5vloAJSBQ -->
            <div class="absolute inset-0 bg-cover bg-center" style="background-image: url('https://images.unsplash.com/photo-1685483749753-0dab7e144794?q=80&w=2400&auto=format&fit=crop');"></div>
            <div class="absolute inset-0 bg-gradient-to-t from-gray-900 via-gray-900/85 to-gray-900/60"></div>
            <div class="absolute inset-0 bg-gradient-to-r from-gray-900 via-gray-900/40 to-transparent"></div>
            <div class="absolute inset-0 bg-[url('data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAwIiBoZWlnaHQ9IjIwMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48ZGVmcz48cGF0dGVybiBpZD0iZ3JpZCIgd2lkdGg9IjQwIiBoZWlnaHQ9IjQwIiBwYXR0ZXJuVW5pdHM9InVzZXJTcGFjZU9uVXNlIj48cGF0aCBkPSJNIDQwIDAgTCAwIDAgMCA0MCIgZmlsbD0ibm9uZSIgc3Ryb2tlPSJyZ2JhKDI1NSwyNTUsMjU1LDAuMDMpIiBzdHJva2Utd2lkdGg9IjEiLz48L3BhdHRlcm4+PC9kZWZzPjxyZWN0IHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIGZpbGw9InVybCgjZ3JpZCkiLz48L3N2Zz4=')] opacity-30"></div>

            <!-- Floating Elements -->
            <div class="absolute top-20 left-10 w-64 h-64 bg-indigo-500 rounded-full mix-blend-multiply filter blur-3xl opacity-10 float-animation"></div>
            <div class="absolute bottom-20 right-10 w-96 h-96 bg-blue-500 rounded-full mix-blend-multiply filter blur-3xl opacity-10 float-animation" style="animation-delay: 2s;"></div>

            <div class="relative z-10 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-20">
                <div class="grid lg:grid-cols-2 gap-12 items-center">
                    <!-- Left Column -->
                    <div class="space-y-8 slide-in-up">
                        <div class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-500/10 border border-indigo-500/20 rounded-full text-indigo-400 text-sm font-medium">
                            <span class="relative flex h-2 w-2">
                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-indigo-400 opacity-75"></span>
                                <span class="relative inline-flex rounded-full h-2 w-2 bg-indigo-500"></span>
                            </span>
                            <span>The Dot Ecosystem's Marketplace Platform</span>
                        </div>

                        <h2 class="text-5xl lg:text-7xl font-bold leading-tight">
                            <span class="bg-gradient-to-r from-white via-gray-100 to-gray-300 bg-clip-text text-transparent">One Marketplace,</span><br>
                            <span class="bg-gradient-to-r from-indigo-400 via-indigo-500 to-blue-500 bg-clip-text text-transparent">Every Store</span>
                        </h2>

                        <p class="text-xl text-gray-400 leading-relaxed max-w-xl">
                            Sellers open a store, list products, and fulfil orders. Buyers shop across every store in the marketplace from one cart and one checkout. Dot.Emall owns the matching layer — listings, catalogue, cart, and order flow.
                        </p>

                        <!-- Real, non-fabricated platform facts -->
                        <div class="flex flex-wrap gap-3 py-2">
                            <span class="inline-flex items-center gap-2 px-4 py-2 bg-gray-800/80 border border-gray-700 rounded-full text-sm text-gray-300">
                                <svg class="w-4 h-4 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                Livewire real-time storefronts
                            </span>
                            <span class="inline-flex items-center gap-2 px-4 py-2 bg-gray-800/80 border border-gray-700 rounded-full text-sm text-gray-300">
                                <svg class="w-4 h-4 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                Single sign-on across the Dot Ecosystem
                            </span>
                            <span class="inline-flex items-center gap-2 px-4 py-2 bg-gray-800/80 border border-gray-700 rounded-full text-sm text-gray-300">
                                <svg class="w-4 h-4 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                Stock-safe checkout
                            </span>
                        </div>

                        @guest
                            <div class="flex flex-wrap gap-4">
                                <a href="{{ route('register') }}" class="group flex items-center gap-2 px-8 py-4 bg-gradient-to-r from-indigo-500 to-indigo-600 hover:from-indigo-600 hover:to-indigo-700 text-white font-bold rounded-xl transition-all duration-300 shadow-2xl shadow-indigo-500/30 transform hover:scale-105">
                                    <span>Open Your Store</span>
                                    <svg class="w-5 h-5 transform group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                                    </svg>
                                </a>
                                <a href="#features" class="flex items-center gap-2 px-8 py-4 bg-gray-800 hover:bg-gray-700 text-white font-semibold rounded-xl transition-all duration-300 border border-gray-700 hover:border-gray-600">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                                    </svg>
                                    <span>See What's Built</span>
                                </a>
                            </div>
                            <p class="text-sm text-gray-500">Sign up as a buyer or a seller — a seller account can open a store from the dashboard</p>
                        @endguest
                    </div>

                    <!-- Right Column - Owner Dashboard Preview -->
                    <div class="relative slide-in-up" style="animation-delay: 0.2s;">
                        <!-- Main Dashboard Card -->
                        <div class="relative bg-gradient-to-br from-gray-800 to-gray-900 rounded-2xl border border-gray-700 shadow-2xl overflow-hidden transform hover:scale-105 transition-transform duration-500">
                            <div class="p-6">
                                <div class="flex items-center justify-between mb-6">
                                    <h3 class="text-lg font-bold text-white">Store Owner Dashboard</h3>
                                    <div class="flex items-center gap-2 px-3 py-1.5 bg-green-500/10 border border-green-500/20 rounded-full">
                                        <span class="relative flex h-2 w-2">
                                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                                            <span class="relative inline-flex rounded-full h-2 w-2 bg-green-500"></span>
                                        </span>
                                        <span class="text-xs text-green-400 font-semibold">Live</span>
                                    </div>
                                </div>

                                <!-- Stats Grid -->
                                <div class="grid grid-cols-2 gap-3 mb-6">
                                    <div class="bg-gradient-to-br from-blue-500/10 to-blue-600/5 rounded-xl p-4 border border-blue-500/20">
                                        <div class="flex items-center justify-between mb-2">
                                            <p class="text-xs text-blue-400 font-medium">Total Products</p>
                                            <svg class="w-4 h-4 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                                            </svg>
                                        </div>
                                        <p class="text-2xl font-bold text-white mb-1">—</p>
                                        <p class="text-xs text-gray-400">across your stores</p>
                                    </div>

                                    <div class="bg-gradient-to-br from-indigo-500/10 to-indigo-600/5 rounded-xl p-4 border border-indigo-500/20">
                                        <div class="flex items-center justify-between mb-2">
                                            <p class="text-xs text-indigo-400 font-medium">Total Orders</p>
                                            <svg class="w-4 h-4 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                                            </svg>
                                        </div>
                                        <p class="text-2xl font-bold text-white mb-1">—</p>
                                        <p class="text-xs text-gray-400">containing your products</p>
                                    </div>

                                    <div class="bg-gradient-to-br from-purple-500/10 to-purple-600/5 rounded-xl p-4 border border-purple-500/20">
                                        <div class="flex items-center justify-between mb-2">
                                            <p class="text-xs text-purple-400 font-medium">Revenue</p>
                                            <svg class="w-4 h-4 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"></path>
                                            </svg>
                                        </div>
                                        <p class="text-2xl font-bold text-white mb-1">—</p>
                                        <p class="text-xs text-gray-400">delivered orders</p>
                                    </div>

                                    <div class="bg-gradient-to-br from-amber-500/10 to-amber-600/5 rounded-xl p-4 border border-amber-500/20">
                                        <div class="flex items-center justify-between mb-2">
                                            <p class="text-xs text-amber-400 font-medium">Pending</p>
                                            <svg class="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                            </svg>
                                        </div>
                                        <p class="text-2xl font-bold text-white mb-1">—</p>
                                        <p class="text-xs text-amber-400">orders awaiting fulfilment</p>
                                    </div>
                                </div>

                                <!-- Recent Orders -->
                                <div class="bg-gray-900/50 rounded-xl p-4 border border-gray-700/50">
                                    <p class="text-xs text-gray-400 font-medium mb-4">Recent Orders</p>
                                    <div class="space-y-2">
                                        <div class="flex items-center justify-between text-xs">
                                            <span class="text-gray-300">Buyer · Product · Store</span>
                                            <span class="px-2 py-0.5 rounded-full bg-amber-500/10 text-amber-400 border border-amber-500/20">pending</span>
                                        </div>
                                        <div class="flex items-center justify-between text-xs">
                                            <span class="text-gray-300">Buyer · Product · Store</span>
                                            <span class="px-2 py-0.5 rounded-full bg-green-500/10 text-green-400 border border-green-500/20">delivered</span>
                                        </div>
                                        <div class="flex items-center justify-between text-xs">
                                            <span class="text-gray-300">Buyer · Product · Store</span>
                                            <span class="px-2 py-0.5 rounded-full bg-amber-500/10 text-amber-400 border border-amber-500/20">pending</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Floating Badge -->
                        <div class="absolute -top-6 -right-6 bg-gradient-to-r from-green-500 to-emerald-500 text-white px-6 py-3 rounded-2xl shadow-2xl shadow-green-500/30 flex items-center gap-2 float-animation transform hover:scale-110 transition-transform">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                            </svg>
                            <span class="font-bold">Order Placed</span>
                        </div>

                        <!-- SSO Badge -->
                        <div class="absolute -bottom-6 -left-6 bg-gradient-to-r from-indigo-500 to-blue-500 text-white px-6 py-3 rounded-2xl shadow-2xl shadow-indigo-500/30 flex items-center gap-2 float-animation transform hover:scale-110 transition-transform" style="animation-delay: 1s;">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                            </svg>
                            <span class="font-bold">Ecosystem SSO</span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Features Section -->
        <section id="features" class="py-24 px-4 sm:px-6 lg:px-8 bg-gray-900/50 relative overflow-hidden">
            <!-- Background Elements -->
            <div class="absolute inset-0 bg-gradient-to-b from-transparent via-indigo-500/5 to-transparent"></div>

            <div class="relative z-10 max-w-7xl mx-auto">
                <div class="text-center mb-16 fade-in-up">
                    <div class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-500/10 border border-indigo-500/20 rounded-full text-indigo-400 text-sm font-medium mb-6">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"></path>
                        </svg>
                        <span>Core Features</span>
                    </div>
                    <h2 class="text-4xl lg:text-5xl font-bold text-white mb-6">
                        Everything a Marketplace<br>
                        <span class="bg-gradient-to-r from-indigo-400 to-blue-500 bg-clip-text text-transparent">Actually Needs</span>
                    </h2>
                    <p class="text-xl text-gray-400 max-w-3xl mx-auto">
                        Built around the real entities that run the marketplace: stores, products, carts, and orders
                    </p>
                </div>

                <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-8">
                    <!-- Feature 1 -->
                    <div class="group bg-gradient-to-br from-gray-800 to-gray-900 p-8 rounded-2xl border border-gray-700 hover:border-indigo-500/50 transition-all duration-300 hover:shadow-2xl hover:shadow-indigo-500/10 transform hover:-translate-y-2 fade-in-up stagger-item">
                        <div class="w-14 h-14 bg-gradient-to-br from-indigo-500 to-indigo-600 rounded-xl flex items-center justify-center mb-6 group-hover:scale-110 transition-transform duration-300 shadow-lg shadow-indigo-500/30">
                            <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 21h18M4 10h16M5 6l7-4 7 4M5 10v11M19 10v11M9 21v-6a2 2 0 012-2h2a2 2 0 012 2v6"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-white mb-3 group-hover:text-indigo-400 transition-colors">Multi-Vendor Storefronts</h3>
                        <p class="text-gray-400 leading-relaxed mb-4">Every seller opens a `Store` owned by their account, with its own slug, logo, and active/inactive status — buyers browse across every store in the marketplace</p>
                        <div class="flex items-center gap-2 text-sm text-indigo-400 font-medium">
                            <span>Store model</span>
                            <svg class="w-4 h-4 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                            </svg>
                        </div>
                    </div>

                    <!-- Feature 2 -->
                    <div class="group bg-gradient-to-br from-gray-800 to-gray-900 p-8 rounded-2xl border border-gray-700 hover:border-indigo-500/50 transition-all duration-300 hover:shadow-2xl hover:shadow-indigo-500/10 transform hover:-translate-y-2 fade-in-up stagger-item">
                        <div class="w-14 h-14 bg-gradient-to-br from-purple-500 to-purple-600 rounded-xl flex items-center justify-center mb-6 group-hover:scale-110 transition-transform duration-300 shadow-lg shadow-purple-500/30">
                            <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-white mb-3 group-hover:text-indigo-400 transition-colors">Product Catalogue</h3>
                        <p class="text-gray-400 leading-relaxed mb-4">Products belong to a store and an optional category, carry price, compare-price, stock, and SKU, and support an ordered image gallery per listing</p>
                        <div class="flex items-center gap-2 text-sm text-indigo-400 font-medium">
                            <span>Product & ProductImage</span>
                            <svg class="w-4 h-4 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                            </svg>
                        </div>
                    </div>

                    <!-- Feature 3 -->
                    <div class="group bg-gradient-to-br from-gray-800 to-gray-900 p-8 rounded-2xl border border-gray-700 hover:border-indigo-500/50 transition-all duration-300 hover:shadow-2xl hover:shadow-indigo-500/10 transform hover:-translate-y-2 fade-in-up stagger-item">
                        <div class="w-14 h-14 bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl flex items-center justify-center mb-6 group-hover:scale-110 transition-transform duration-300 shadow-lg shadow-blue-500/30">
                            <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-white mb-3 group-hover:text-indigo-400 transition-colors">Cart & Stock-Safe Checkout</h3>
                        <p class="text-gray-400 leading-relaxed mb-4">One `CartItem` row per buyer/product, checked out through a transaction that locks product rows and guards the stock decrement so it can never go negative under concurrent checkouts</p>
                        <div class="flex items-center gap-2 text-sm text-indigo-400 font-medium">
                            <span>CartController::checkout()</span>
                            <svg class="w-4 h-4 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                            </svg>
                        </div>
                    </div>

                    <!-- Feature 4 -->
                    <div class="group bg-gradient-to-br from-gray-800 to-gray-900 p-8 rounded-2xl border border-gray-700 hover:border-indigo-500/50 transition-all duration-300 hover:shadow-2xl hover:shadow-indigo-500/10 transform hover:-translate-y-2 fade-in-up stagger-item">
                        <div class="w-14 h-14 bg-gradient-to-br from-green-500 to-green-600 rounded-xl flex items-center justify-center mb-6 group-hover:scale-110 transition-transform duration-300 shadow-lg shadow-green-500/30">
                            <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-white mb-3 group-hover:text-indigo-400 transition-colors">Order Tracking</h3>
                        <p class="text-gray-400 leading-relaxed mb-4">Checkout turns cart items into a real `Order` with `OrderItem` line items; buyers see their own order history and sellers see orders containing their products</p>
                        <div class="flex items-center gap-2 text-sm text-indigo-400 font-medium">
                            <span>Order & OrderItem</span>
                            <svg class="w-4 h-4 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                            </svg>
                        </div>
                    </div>

                    <!-- Feature 5 -->
                    <div class="group bg-gradient-to-br from-gray-800 to-gray-900 p-8 rounded-2xl border border-gray-700 hover:border-indigo-500/50 transition-all duration-300 hover:shadow-2xl hover:shadow-indigo-500/10 transform hover:-translate-y-2 fade-in-up stagger-item">
                        <div class="w-14 h-14 bg-gradient-to-br from-amber-500 to-amber-600 rounded-xl flex items-center justify-center mb-6 group-hover:scale-110 transition-transform duration-300 shadow-lg shadow-amber-500/30">
                            <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.196-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-white mb-3 group-hover:text-indigo-400 transition-colors">Ratings & Reviews</h3>
                        <p class="text-gray-400 leading-relaxed mb-4">Buyers leave one rating and review per product, scoped by an `OrderPolicy`/`ReviewPolicy` pair so only order and review owners can act on their own records</p>
                        <div class="flex items-center gap-2 text-sm text-indigo-400 font-medium">
                            <span>Review model</span>
                            <svg class="w-4 h-4 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                            </svg>
                        </div>
                    </div>

                    <!-- Feature 6 -->
                    <div class="group bg-gradient-to-br from-gray-800 to-gray-900 p-8 rounded-2xl border border-gray-700 hover:border-indigo-500/50 transition-all duration-300 hover:shadow-2xl hover:shadow-indigo-500/10 transform hover:-translate-y-2 fade-in-up stagger-item">
                        <div class="w-14 h-14 bg-gradient-to-br from-pink-500 to-pink-600 rounded-xl flex items-center justify-center mb-6 group-hover:scale-110 transition-transform duration-300 shadow-lg shadow-pink-500/30">
                            <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-white mb-3 group-hover:text-indigo-400 transition-colors">Category Browsing</h3>
                        <p class="text-gray-400 leading-relaxed mb-4">A self-referential `StoreCategory` tree lets sellers organise listings into categories and sub-categories that buyers can filter by while browsing</p>
                        <div class="flex items-center gap-2 text-sm text-indigo-400 font-medium">
                            <span>StoreCategory model</span>
                            <svg class="w-4 h-4 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                            </svg>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Capabilities Section -->
        <section id="capabilities" class="py-24 px-4 sm:px-6 lg:px-8 relative overflow-hidden">
            <div class="absolute inset-0 bg-gradient-to-br from-gray-900 via-gray-800 to-gray-900"></div>

            <div class="relative z-10 max-w-7xl mx-auto">
                <div class="text-center mb-16 fade-in-up">
                    <div class="inline-flex items-center gap-2 px-4 py-2 bg-blue-500/10 border border-blue-500/20 rounded-full text-blue-400 text-sm font-medium mb-6">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                        </svg>
                        <span>Platform Capabilities</span>
                    </div>
                    <h2 class="text-4xl lg:text-5xl font-bold text-white mb-6">
                        Built as a Real<br>
                        <span class="bg-gradient-to-r from-blue-400 to-cyan-400 bg-clip-text text-transparent">Ecosystem Citizen</span>
                    </h2>
                    <p class="text-xl text-gray-400 max-w-3xl mx-auto">
                        Dot.Emall isn't a standalone store — it plugs into the shared Dot Ecosystem infrastructure
                    </p>
                </div>

                <div class="grid lg:grid-cols-2 gap-8 mb-8">
                    <!-- Left Column -->
                    <div class="space-y-6">
                        <div class="bg-gradient-to-br from-gray-800 to-gray-900 p-8 rounded-2xl border border-gray-700 hover:border-blue-500/50 transition-all duration-300 fade-in-up">
                            <div class="flex items-start gap-4">
                                <div class="w-12 h-12 bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl flex items-center justify-center flex-shrink-0 shadow-lg shadow-blue-500/30">
                                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h3 class="text-xl font-bold text-white mb-3">Ecosystem Single Sign-On</h3>
                                    <p class="text-gray-400 leading-relaxed">A user authenticated anywhere else in the Dot Ecosystem lands here already signed in via a scoped, one-time Sanctum token exchanged at `/auth/ecosystem`.</p>
                                </div>
                            </div>
                        </div>

                        <div class="bg-gradient-to-br from-gray-800 to-gray-900 p-8 rounded-2xl border border-gray-700 hover:border-blue-500/50 transition-all duration-300 fade-in-up">
                            <div class="flex items-start gap-4">
                                <div class="w-12 h-12 bg-gradient-to-br from-purple-500 to-purple-600 rounded-xl flex items-center justify-center flex-shrink-0 shadow-lg shadow-purple-500/30">
                                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h3 class="text-xl font-bold text-white mb-3">Shared Ecosystem Database</h3>
                                    <p class="text-gray-400 leading-relaxed">Runs on PostgreSQL 16 shared across the Dot Ecosystem (`infodot`), with `stores`, `products`, and `orders` namespaced by table name alongside every other platform.</p>
                                </div>
                            </div>
                        </div>

                        <div class="bg-gradient-to-br from-gray-800 to-gray-900 p-8 rounded-2xl border border-gray-700 hover:border-blue-500/50 transition-all duration-300 fade-in-up">
                            <div class="flex items-start gap-4">
                                <div class="w-12 h-12 bg-gradient-to-br from-emerald-500 to-emerald-600 rounded-xl flex items-center justify-center flex-shrink-0 shadow-lg shadow-emerald-500/30">
                                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h3 class="text-xl font-bold text-white mb-3">Real-Time Storefronts</h3>
                                    <p class="text-gray-400 leading-relaxed">Livewire 3 and Alpine.js power the buyer-facing storefront and owner dashboard, with Laravel Reverb wired in for realtime updates.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column -->
                    <div class="space-y-6">
                        <div class="bg-gradient-to-br from-gray-800 to-gray-900 p-8 rounded-2xl border border-gray-700 hover:border-blue-500/50 transition-all duration-300 fade-in-up">
                            <div class="flex items-start gap-4">
                                <div class="w-12 h-12 bg-gradient-to-br from-orange-500 to-orange-600 rounded-xl flex items-center justify-center flex-shrink-0 shadow-lg shadow-orange-500/30">
                                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h3 class="text-xl font-bold text-white mb-3">Real Domain Events</h3>
                                    <p class="text-gray-400 leading-relaxed">`App\Events\OrderPlaced` dispatches from checkout once the order transaction commits — the first genuine domain event this codebase fires, not a conceptual placeholder.</p>
                                </div>
                            </div>
                        </div>

                        <div class="bg-gradient-to-br from-gray-800 to-gray-900 p-8 rounded-2xl border border-gray-700 hover:border-blue-500/50 transition-all duration-300 fade-in-up">
                            <div class="flex items-start gap-4">
                                <div class="w-12 h-12 bg-gradient-to-br from-pink-500 to-pink-600 rounded-xl flex items-center justify-center flex-shrink-0 shadow-lg shadow-pink-500/30">
                                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h3 class="text-xl font-bold text-white mb-3">Signed Knowledge Packs</h3>
                                    <p class="text-gray-400 leading-relaxed">Dot.Emall published its first real, Ed25519-signed Knowledge Pack to Dot.Brain on 2026-08-02 — an `incident_report` payload independently re-verified against the committed public key.</p>
                                </div>
                            </div>
                        </div>

                        <div class="bg-gradient-to-br from-gray-800 to-gray-900 p-8 rounded-2xl border border-gray-700 hover:border-blue-500/50 transition-all duration-300 fade-in-up">
                            <div class="flex items-start gap-4">
                                <div class="w-12 h-12 bg-gradient-to-br from-cyan-500 to-cyan-600 rounded-xl flex items-center justify-center flex-shrink-0 shadow-lg shadow-cyan-500/30">
                                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h3 class="text-xl font-bold text-white mb-3">Owner Dashboard</h3>
                                    <p class="text-gray-400 leading-relaxed">The seller-facing dashboard resolves stores, products, and orders you own, then surfaces product/order counts, delivered revenue, pending orders, and your eight most recent orders.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- How It Works Section -->
        <section id="how-it-works" class="py-24 px-4 sm:px-6 lg:px-8 bg-gray-900/50 relative overflow-hidden">
            <div class="relative z-10 max-w-5xl mx-auto">
                <div class="text-center mb-16 fade-in-up">
                    <h2 class="text-4xl lg:text-5xl font-bold text-white mb-6">
                        From Cart to <span class="bg-gradient-to-r from-indigo-400 to-blue-500 bg-clip-text text-transparent">Delivered Order</span>
                    </h2>
                    <p class="text-xl text-gray-400 max-w-3xl mx-auto">
                        The real flow a buyer's order takes through the codebase today
                    </p>
                </div>

                <div class="grid md:grid-cols-4 gap-6">
                    <div class="bg-gradient-to-br from-gray-800 to-gray-900 p-6 rounded-2xl border border-gray-700 text-center">
                        <div class="w-10 h-10 mx-auto mb-4 rounded-full bg-indigo-500/10 border border-indigo-500/30 flex items-center justify-center text-indigo-400 font-bold">1</div>
                        <h3 class="text-white font-semibold mb-2">Add to Cart</h3>
                        <p class="text-sm text-gray-400">One `CartItem` row per buyer/product, quantity tracked per row</p>
                    </div>
                    <div class="bg-gradient-to-br from-gray-800 to-gray-900 p-6 rounded-2xl border border-gray-700 text-center">
                        <div class="w-10 h-10 mx-auto mb-4 rounded-full bg-indigo-500/10 border border-indigo-500/30 flex items-center justify-center text-indigo-400 font-bold">2</div>
                        <h3 class="text-white font-semibold mb-2">Checkout</h3>
                        <p class="text-sm text-gray-400">Product rows are locked and re-checked for stock inside the transaction</p>
                    </div>
                    <div class="bg-gradient-to-br from-gray-800 to-gray-900 p-6 rounded-2xl border border-gray-700 text-center">
                        <div class="w-10 h-10 mx-auto mb-4 rounded-full bg-indigo-500/10 border border-indigo-500/30 flex items-center justify-center text-indigo-400 font-bold">3</div>
                        <h3 class="text-white font-semibold mb-2">Order Placed</h3>
                        <p class="text-sm text-gray-400">An `Order` with `OrderItem` rows is created and `OrderPlaced` is dispatched</p>
                    </div>
                    <div class="bg-gradient-to-br from-gray-800 to-gray-900 p-6 rounded-2xl border border-gray-700 text-center">
                        <div class="w-10 h-10 mx-auto mb-4 rounded-full bg-indigo-500/10 border border-indigo-500/30 flex items-center justify-center text-indigo-400 font-bold">4</div>
                        <h3 class="text-white font-semibold mb-2">Fulfilled</h3>
                        <p class="text-sm text-gray-400">Sellers track status from `pending` to `delivered` in their dashboard</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- CTA Section -->
        <section class="py-24 px-4 sm:px-6 lg:px-8 relative overflow-hidden">
            <!-- Photographic Background: real cardboard-boxes-and-packaging-supplies-in-a-warehouse photo by Guilherme Mendes (@guilhermemendes), unsplash.com/photos/cardboard-boxes-and-packaging-supplies-in-a-warehouse-RVP3wAw9gJY -->
            <div class="absolute inset-0 bg-cover bg-center" style="background-image: url('https://images.unsplash.com/photo-1769355104335-acef3aa4c9b6?q=80&w=2400&auto=format&fit=crop');"></div>
            <div class="absolute inset-0 bg-gray-900/90"></div>
            <div class="absolute inset-0 bg-gradient-to-br from-indigo-600/20 via-indigo-500/10 to-transparent"></div>
            <div class="absolute inset-0 bg-[url('data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAwIiBoZWlnaHQ9IjIwMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48ZGVmcz48cGF0dGVybiBpZD0iZ3JpZCIgd2lkdGg9IjQwIiBoZWlnaHQ9IjQwIiBwYXR0ZXJuVW5pdHM9InVzZXJTcGFjZU9uVXNlIj48cGF0aCBkPSJNIDQwIDAgTCAwIDAgMCA0MCIgZmlsbD0ibm9uZSIgc3Ryb2tlPSJyZ2JhKDI1NSwyNTUsMjU1LDAuMDMpIiBzdHJva2Utd2lkdGg9IjEiLz48L3BhdHRlcm4+PC9kZWZzPjxyZWN0IHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIGZpbGw9InVybCgjZ3JpZCkiLz48L3N2Zz4=')] opacity-30"></div>

            <div class="relative z-10 max-w-4xl mx-auto text-center fade-in-up">
                <h2 class="text-4xl lg:text-5xl font-bold text-white mb-6">
                    Ready to Open<br>
                    <span class="bg-gradient-to-r from-indigo-400 to-blue-500 bg-clip-text text-transparent">Your Store?</span>
                </h2>
                <p class="text-xl text-gray-400 mb-10 max-w-2xl mx-auto">
                    List products, take orders, and fulfil them — or just shop across every store in the marketplace as a buyer
                </p>

                @guest
                    <div class="flex flex-wrap justify-center gap-4 mb-8">
                        <a href="{{ route('register') }}" class="group flex items-center gap-2 px-10 py-4 bg-gradient-to-r from-indigo-500 to-indigo-600 hover:from-indigo-600 hover:to-indigo-700 text-white font-bold rounded-xl transition-all duration-300 shadow-2xl shadow-indigo-500/30 transform hover:scale-105">
                            <span>Create an Account</span>
                            <svg class="w-5 h-5 transform group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                            </svg>
                        </a>
                        <a href="{{ route('login') }}" class="flex items-center gap-2 px-10 py-4 bg-gray-800 hover:bg-gray-700 text-white font-semibold rounded-xl transition-all duration-300 border border-gray-700 hover:border-gray-600">
                            <span>Sign In</span>
                        </a>
                    </div>
                @endguest
            </div>
        </section>

        <!-- Footer -->
        <footer class="py-12 px-4 sm:px-6 lg:px-8 border-t border-gray-800 bg-gray-900/50">
            <div class="max-w-7xl mx-auto">
                <div class="grid md:grid-cols-4 gap-8 mb-8">
                    <!-- Brand -->
                    <div class="col-span-1">
                        <img src="{{ asset('images/logo.png') }}" alt="Dot.Emall" class="h-12 w-auto mb-4">
                        <p class="text-gray-400 text-sm">
                            The multi-vendor marketplace platform in the Dot Ecosystem.
                        </p>
                    </div>

                    <!-- Links -->
                    <div>
                        <h3 class="text-white font-semibold mb-4">Product</h3>
                        <ul class="space-y-2 text-sm">
                            <li><a href="#features" class="text-gray-400 hover:text-indigo-400 transition-colors">Features</a></li>
                            <li><a href="#capabilities" class="text-gray-400 hover:text-indigo-400 transition-colors">Capabilities</a></li>
                            <li><a href="#how-it-works" class="text-gray-400 hover:text-indigo-400 transition-colors">How It Works</a></li>
                        </ul>
                    </div>

                    <div>
                        <h3 class="text-white font-semibold mb-4">Company</h3>
                        <ul class="space-y-2 text-sm">
                            <li><a href="#" class="text-gray-400 hover:text-indigo-400 transition-colors">About Us</a></li>
                            <li><a href="#" class="text-gray-400 hover:text-indigo-400 transition-colors">Contact</a></li>
                            <li><a href="#" class="text-gray-400 hover:text-indigo-400 transition-colors">Careers</a></li>
                        </ul>
                    </div>

                    <div>
                        <h3 class="text-white font-semibold mb-4">Legal</h3>
                        <ul class="space-y-2 text-sm">
                            <li><a href="#" class="text-gray-400 hover:text-indigo-400 transition-colors">Privacy Policy</a></li>
                            <li><a href="#" class="text-gray-400 hover:text-indigo-400 transition-colors">Terms of Service</a></li>
                            <li><a href="#" class="text-gray-400 hover:text-indigo-400 transition-colors">Cookie Policy</a></li>
                        </ul>
                    </div>
                </div>

                <div class="pt-8 border-t border-gray-800 flex flex-col md:flex-row items-center justify-between gap-4">
                    <p class="text-gray-400 text-sm">
                        &copy; {{ date('Y') }} Dot.Emall. All rights reserved.
                    </p>
                    <div class="flex items-center gap-4">
                        <a href="#" class="text-gray-400 hover:text-indigo-400 transition-colors">
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
                            </svg>
                        </a>
                        <a href="#" class="text-gray-400 hover:text-indigo-400 transition-colors">
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M23.953 4.57a10 10 0 01-2.825.775 4.958 4.958 0 002.163-2.723c-.951.555-2.005.959-3.127 1.184a4.92 4.92 0 00-8.384 4.482C7.69 8.095 4.067 6.13 1.64 3.162a4.822 4.822 0 00-.666 2.475c0 1.71.87 3.213 2.188 4.096a4.904 4.904 0 01-2.228-.616v.06a4.923 4.923 0 003.946 4.827 4.996 4.996 0 01-2.212.085 4.936 4.936 0 004.604 3.417 9.867 9.867 0 01-6.102 2.105c-.39 0-.779-.023-1.17-.067a13.995 13.995 0 007.557 2.209c9.053 0 13.998-7.496 13.998-13.985 0-.21 0-.42-.015-.63A9.935 9.935 0 0024 4.59z"/>
                            </svg>
                        </a>
                        <a href="#" class="text-gray-400 hover:text-indigo-400 transition-colors">
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/>
                            </svg>
                        </a>
                    </div>
                </div>
            </div>
        </footer>

    </body>
</html>
