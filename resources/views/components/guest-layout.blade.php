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
                        <span class="text-2xl font-bold text-gray-800">AccioJob</span>
                    </div>
                </div>

                {{ $slot }}
            </div>
        </div>

        <!-- Right Side (Banner) -->
        <div class="hidden lg:flex w-1/2 bg-[#2d007c] text-white flex-col justify-center relative overflow-hidden" 
             style="background-image: url('/images/auth_hero_banner.png'); background-size: cover; background-position: center;">
            <div class="absolute inset-0 bg-[#2d007c]/70 mix-blend-multiply"></div>
            
            <div class="relative z-10 p-16 flex flex-col h-full justify-between">
                <div>
                    <h2 class="text-4xl font-bold mb-4 leading-tight">Over 500+ Partner Companies Hire Our Students</h2>
                    <p class="text-lg text-gray-200">AccioJob is the most trusted training & placement platform.</p>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
