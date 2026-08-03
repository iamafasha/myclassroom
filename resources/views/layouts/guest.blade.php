<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Laravel') }} - Auth</title>
    
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans antialiased bg-white text-gray-900">
    <div class="flex h-screen w-full">
        <!-- Left Side (Form) -->
        <div class="w-full lg:w-1/2 flex flex-col justify-center items-center p-8 lg:p-12">
            <div class="w-full max-w-md">
                <!-- Logo -->
                <div class="flex justify-center mb-10">
                    <div class="flex items-center gap-2">
                        <svg class="w-8 h-8 text-blue-600" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5" />
                        </svg>
                        <span class="text-2xl font-bold text-gray-800">Classroom</span>
                    </div>
                </div>

                {{ $slot }}
            </div>
        </div>

        <!-- Right Side (Banner) -->
        <div class="hidden lg:flex w-1/2 bg-[#2d007c] text-white flex-col justify-center relative overflow-hidden">
            <img src="{{ asset('images/auth_hero.jpg') }}" alt="Students in a classroom"
                 class="absolute inset-0" style="height: 100%; width: 100%; object-fit: cover;">
            <div class="absolute inset-0 bg-[#2d007c]/70 mix-blend-multiply"></div>
            <div class="absolute inset-0" style="background: linear-gradient(to top, rgba(27,0,73,0.85) 0%, rgba(27,0,73,0.15) 45%, rgba(27,0,73,0.6) 100%);"></div>

            <div class="relative z-10 p-16 flex flex-col h-full justify-between">
                <div>
                    <h2 class="text-4xl font-bold mb-4 leading-tight">Everything you're learning, in one simple place</h2>
                    <p class="text-lg text-gray-200">Open a course, follow the modules in order and submit your exercises as you go. No setup, no clutter — just learning.</p>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
