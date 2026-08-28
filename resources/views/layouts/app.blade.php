<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>Classroom Dashboard</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
        @livewireStyles
        <style>
            :root {
                --sidebar-width: 72px;
                --panel-list-width: 420px;
                --bg-main: #ffffff;
                --bg-shell: #F3F1F6;
                --text-primary: #111827;
                --text-secondary: #6B7280;
                --primary-blue: #2563EB;
                --border-color: #E5E7EB;
            }

            * { box-sizing: border-box; }

            [x-cloak] { display: none !important; }

            body {
                margin: 0;
                font-family: 'Inter', system-ui, -apple-system, sans-serif;
                background-color: var(--bg-shell);
                color: var(--text-primary);
                display: flex;
                height: 100svh;
                overflow: hidden;
                padding: 12px;
                gap: 12px;
            }

            .sidebar {
                width: var(--sidebar-width);
                background-color: #ffffff;
                border-radius: 20px;
                display: flex;
                flex-direction: column;
                align-items: center;
                padding: 20px 0;
                flex-shrink: 0;
                box-shadow: 0 1px 2px rgba(16, 24, 40, 0.04), 0 8px 24px rgba(16, 24, 40, 0.05);
            }

            .sidebar-logo {
                margin-bottom: 28px;
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .sidebar-nav {
                display: flex;
                flex-direction: column;
                align-items: center;
                gap: 10px;
            }

            /* Bottom cluster (settings, sign out) hugs the base of the rail. */
            .sidebar-footer {
                margin-top: auto;
                display: flex;
                flex-direction: column;
                align-items: center;
                gap: 10px;
                padding-top: 20px;
            }

            .sidebar-item {
                width: 42px;
                height: 42px;
                display: flex;
                align-items: center;
                justify-content: center;
                border-radius: 13px;
                border: none;
                background: transparent;
                color: #9CA3AF;
                cursor: pointer;
                position: relative;
                transition: background-color 0.15s, color 0.15s;
            }

            .sidebar-item.active {
                background-color: #111827;
                color: #ffffff;
            }

            .sidebar-item:hover:not(.active) {
                background-color: #F3F4F6;
                color: #374151;
            }

            .main-layout {
                display: flex;
                flex: 1;
                overflow: hidden;
                background-color: var(--bg-main);
                border-radius: 20px;
                box-shadow: 0 1px 2px rgba(16, 24, 40, 0.04), 0 8px 24px rgba(16, 24, 40, 0.05);
            }

            .panel-list {
                width: var(--panel-list-width);
                background-color: #F9FAFB;
                border-right: 1px solid var(--border-color);
                display: flex;
                flex-direction: column;
                height: 100%;
                overflow-y: auto;
            }

            .panel-content {
                overflow-y: auto;
                overflow-x: hidden;
            }

            .course-selector {
                margin-bottom: 20px;
            }

            .select-styled {
                width: 100%;
                padding: 10px 15px;
                border-radius: 8px;
                border: 1px solid #D1D5DB;
                background: #F3F4F6;
                font-size: 13px;
                font-weight: 500;
                appearance: none;
                background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%236B7280'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'%3E%3C/path%3E%3C/svg%3E");
                background-repeat: no-repeat;
                background-position: right 10px center;
                background-size: 16px;
            }

            .custom-select-dropdown {
                position: absolute;
                top: 100%;
                left: 0;
                right: 0;
                z-index: 50;
                background: #ffffff;
                border: 1px solid #D1D5DB;
                border-radius: 8px;
                margin-top: 4px;
                box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
                max-height: 250px;
                overflow-y: auto;
            }

            .custom-select-option {
                padding: 10px 15px;
                cursor: pointer;
                font-size: 13px;
                font-weight: 500;
                color: var(--text-primary);
                transition: background-color 0.2s, color 0.2s;
            }

            .custom-select-option:not(:last-child) {
                border-bottom: 1px solid #F3F4F6;
            }

            .custom-select-option:hover {
                background-color: #EFF6FF;
                color: #2563EB;
            }

            .custom-select-option.selected {
                background-color: #EFF6FF;
                color: #2563EB;
                font-weight: 600;
            }

            .progress-container {
                margin-bottom: 25px;
            }

            .progress-card {
                background: #ffffff;
                border: 1px solid #FEE2E2;
                border-radius: 12px;
                padding: 20px;
                box-shadow: 0 1px 2px rgba(0,0,0,0.05);
            }

            .progress-bar-wrapper {
                position: relative;
                height: 8px;
                background-color: #FEE2E2;
                border-radius: 4px;
                margin: 25px 0 15px;
            }

            .progress-bar-fill {
                height: 100%;
                background-color: #EF4444;
                border-radius: 4px;
                width: 21%;
            }

            .progress-tooltip {
                position: absolute;
                top: -30px;
                left: 21%;
                transform: translateX(-50%);
                background-color: #EF4444;
                color: white;
                padding: 2px 8px;
                border-radius: 4px;
                font-size: 11px;
                font-weight: 700;
            }

            .progress-tooltip::after {
                content: '';
                position: absolute;
                bottom: -4px;
                left: 50%;
                transform: translateX(-50%);
                border-left: 4px solid transparent;
                border-right: 4px solid transparent;
                border-top: 4px solid #EF4444;
            }

            .total-info {
                text-align: center;
                font-size: 12px;
                color: #4B5563;
                background: #F9FAFB;
                padding: 4px 10px;
                border-radius: 20px;
                width: fit-content;
                margin: 0 auto;
                border: 1px solid #E5E7EB;
            }

            .module-list {
                display: flex;
                flex-direction: column;
                gap: 12px;
            }

            .module-card {
                border-radius: 8px;
                padding: 16px;
                border: 1px solid transparent;
                cursor: pointer;
                display: flex;
                flex-direction: column;
                transition: transform 0.1s;
            }

            .module-card:active { transform: scale(0.99); }

            .module-card.js { background-color: #FEF2F2; border-color: #FEE2E2; }
            .module-card.design { background-color: #F0FDF4; border-color: #DCFCE7; }
            .module-card.active { background-color: #ffffff; border-color: #2563EB; box-shadow: 0 2px 4px rgba(37, 99, 235, 0.1); }

            .module-header {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                margin-bottom: 4px;
            }

            .module-date { font-size: 10px; color: #9CA3AF; text-transform: uppercase; letter-spacing: 0.5px; }

            /* Study date chip on a content row: muted when it is just the day the content
               was added, accented once the owner has planned a start date for it. */
            .content-date {
                font-size: 10px;
                color: #9CA3AF;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                display: inline-flex;
                align-items: center;
                gap: 4px;
            }

            .content-date.planned {
                color: #4338CA;
                background: #EEF2FF;
                border: 1px solid #C7D2FE;
                border-radius: 9999px;
                padding: 2px 8px;
                font-weight: 700;
            }

            .module-meta-right { font-size: 10px; text-align: right; }
            .meta-item { margin-bottom: 2px; }
            .meta-contents { color: #EF4444; }
            .meta-videos { color: #10B981; }

            .module-body {
                display: flex;
                justify-content: space-between;
                align-items: flex-end;
            }

            .module-title { font-weight: 600; font-size: 13.5px; color: #111827; max-width: 75%; }
            .active .module-title { color: #2563EB; }

            .content-header { margin-bottom: 20px; }
            .content-breadcrumb { font-size: 11px; font-weight: 700; color: #6B7280; text-transform: uppercase; margin-bottom: 8px; }
            .content-title { font-size: 24px; font-weight: 800; color: #111827; margin: 0; }

            .tabs-container {
                display: flex;
                gap: 30px;
                border-bottom: 1px solid #F3F4F6;
                margin-bottom: 30px;
                margin-top: 20px;
            }

            .tab-item {
                padding: 10px 0;
                font-size: 12px;
                font-weight: 700;
                color: #6B7280;
                cursor: pointer;
                text-transform: uppercase;
                border-bottom: 2px solid transparent;
                transition: all 0.2s;
            }

            .tab-item.active {
                color: #2563EB;
                border-bottom-color: #2563EB;
            }

            .contents-list {
                display: flex;
                flex-direction: column;
                gap: 15px;
            }

            .content-card {
                background: #ffffff;
                border: 1px solid #E5E7EB;
                border-radius: 12px;
                padding: 20px;
                display: flex;
                justify-content: space-between;
                align-items: center;
                transition: box-shadow 0.2s;
            }

            .content-card:hover {
                box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            }

            .content-info {
                display: flex;
                flex-direction: column;
                gap: 10px;
            }

            .content-name {
                font-size: 15px;
                font-weight: 700;
                color: #1F2937;
            }

            .content-details {
                display: flex;
                gap: 20px;
                align-items: center;
                font-size: 12px;
                color: #6B7280;
            }

            .badge {
                padding: 2px 8px;
                border-radius: 4px;
                font-weight: 700;
                font-size: 10px;
                text-transform: uppercase;
            }

            .badge-medium { background-color: #FFFBEB; color: #D97706; border: 1px solid #FEF3C7; }

            .content-score-item { display: flex; align-items: center; gap: 5px; }

            .action-area {
                display: flex;
                align-items: center;
            }

            .btn-solve {
                background-color: #312E81;
                color: white;
                padding: 10px 20px;
                border-radius: 8px;
                font-size: 13px;
                font-weight: 600;
                border: none;
                cursor: pointer;
                display: flex;
                align-items: center;
                gap: 8px;
            }

            .btn-solved {
                background-color: #F0FDF4;
                color: #16A34A;
                padding: 10px 40px;
                border-radius: 8px;
                font-size: 13px;
                font-weight: 600;
                border: 1px solid #DCFCE7;
            }
            
            .star-icon { color: #F59E0B; margin-left: 5px; }

            /*
             * Phones / small tablets: the desktop shell is a horizontal flex with a
             * fixed side rail and non-scrolling body. Re-flow it into a vertical stack
             * with the rail as a bottom navigation bar, and let the two-pane inner
             * layouts stack instead of sitting side by side.
             */
            @media (max-width: 820px) {
                body {
                    flex-direction: column;
                    padding: 0;
                    gap: 0;
                    height: 100svh;
                }

                .main-layout {
                    order: 1;
                    flex: 1;
                    min-height: 0;
                    flex-direction: column;
                    overflow-y: auto;
                    border-radius: 0;
                    box-shadow: none;
                }

                /* Side rail -> bottom bar. */
                .sidebar {
                    order: 2;
                    width: 100%;
                    flex-direction: row;
                    justify-content: space-around;
                    align-items: center;
                    border-radius: 0;
                    border-top: 1px solid var(--border-color);
                    padding: 4px 6px;
                    padding-bottom: calc(4px + env(safe-area-inset-bottom));
                    box-shadow: 0 -1px 3px rgba(16, 24, 40, 0.08);
                    overflow-x: auto;
                }

                .sidebar-logo { display: none; }

                .sidebar-nav {
                    flex-direction: row;
                    gap: 2px;
                    flex: 1;
                    justify-content: space-around;
                }

                .sidebar-footer {
                    flex-direction: row;
                    margin-top: 0;
                    padding-top: 0;
                    gap: 2px;
                }

                .sidebar-item { width: 40px; height: 40px; }

                /* Two-pane inner layouts stack vertically. */
                .panel-list,
                .panel-content {
                    width: 100% !important;
                    flex: none;
                }

                /* On a course page the module rail is a capped, scrollable strip
                   above the reading pane; the page as a whole scrolls. */
                .has-nav-panel {
                    display: block;
                    overflow-y: auto;
                }

                .has-nav-panel .panel-list {
                    height: auto;
                    max-height: 42vh;
                    border-right: none;
                    border-bottom: 1px solid var(--border-color);
                }

                .has-nav-panel .panel-content {
                    height: auto;
                    padding: 18px 16px 40px !important;
                }

                /* Standalone reading view (content-show) fills the width with
                   comfortable page margins instead of the desktop 40px. */
                .panel-list[style*="padding: 40px"] { padding: 18px 16px 40px !important; }

                .content-title { font-size: 20px; }

                .content-header {
                    flex-wrap: wrap;
                    gap: 12px;
                }

                .tabs-container {
                    gap: 16px;
                    overflow-x: auto;
                    -webkit-overflow-scrolling: touch;
                    margin-bottom: 20px;
                }

                .content-card { padding: 16px; }

                .content-details { flex-wrap: wrap; }

                /* Course content rows stay a single line: number, title/meta, action.
                   The drag handle is meaningless on touch, so it goes. */
                .contents-list .content-card {
                    align-items: center;
                    gap: 10px;
                    padding: 14px;
                }

                .contents-list .content-card .content-info { flex: 1; min-width: 0; }
                .contents-list .content-card .action-area { flex-shrink: 0; }

                /* Touch has no hover and no drag: the reorder handle and the
                   hover-reveal owner tools are desktop-only. The reading list
                   stays a clean "title -> View" on a phone; structural edits and
                   per-block Edit/Delete live on the content page and on desktop. */
                .contents-list .content-card [title="Drag to move to another module"],
                .contents-list .content-card .opacity-0,
                .module-card .opacity-0 { display: none !important; }

                .btn-solve,
                .btn-solved { padding: 10px 16px; }

                /* Views whose root is a side-by-side flex (a fixed-width form/filter
                   pane next to a list) collapse into a single scrolling column. */
                .responsive-shell {
                    flex-direction: column !important;
                    height: auto !important;
                    overflow: visible !important;
                    gap: 0 !important;
                }

                .responsive-shell > * {
                    width: 100% !important;
                    flex: none !important;
                }

                /* Notifications popover: anchored to the bottom bar, it must not
                   run off a narrow screen. */
                .notif-panel {
                    left: 8px !important;
                    right: 8px !important;
                    bottom: 8px !important;
                    width: auto !important;
                }
            }

            /*
             * Print: the app is a fixed-height flex shell with its own scrolling
             * panels, so the browser would otherwise print only the first screenful.
             * Unclamp the heights, drop the chrome, and let content flow onto pages.
             */
            @media print {
                @page { margin: 1.5cm; }

                html, body {
                    height: auto !important;
                    overflow: visible !important;
                    display: block !important;
                    padding: 0 !important;
                    margin: 0 !important;
                    background: #ffffff !important;
                }

                /* Navigation chrome and editing controls have no place on paper. */
                .sidebar,
                .action-area,
                .no-print {
                    display: none !important;
                }

                /* Only the left rail on screens that have a separate content pane;
                 * elsewhere .panel-list IS the page body, so it must stay. */
                .has-nav-panel .panel-list {
                    display: none !important;
                }

                .main-layout {
                    display: block !important;
                    overflow: visible !important;
                    height: auto !important;
                    border: 0 !important;
                    border-radius: 0 !important;
                    box-shadow: none !important;
                }

                .panel-content,
                .panel-list {
                    display: block !important;
                    overflow: visible !important;
                    height: auto !important;
                    width: 100% !important;
                }

                .panel-content { padding: 0 !important; }

                /* Keep a content item whole rather than split across a page break. */
                .content-card {
                    break-inside: avoid;
                    page-break-inside: avoid;
                    box-shadow: none !important;
                    transition: none !important;
                }

                /* Preserve the badge / status colours instead of flattening them. */
                * {
                    -webkit-print-color-adjust: exact !important;
                    print-color-adjust: exact !important;
                }
            }
        </style>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        

    </head>
    <body>
        <div class="sidebar">
            <a href="{{ route('home') }}" class="sidebar-logo" title="Classroom">
                <svg width="30" height="30" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <rect width="32" height="32" rx="9" fill="#111827"/>
                    <path d="M16 8L8 14V24H12V18H20V24H24V14L16 8Z" fill="white"/>
                </svg>
            </a>

            <div class="sidebar-nav">
                <a href="{{ route('home') }}" class="sidebar-item {{ request()->routeIs('home') ? 'active' : '' }}" title="Home">
                    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z" />
                    </svg>
                </a>

                <a href="{{ route('dashboard') }}" class="sidebar-item {{ request()->routeIs('dashboard') || request()->routeIs('course.*') || request()->routeIs('content.*') ? 'active' : '' }}" title="Course browser">
                    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 5a1 1 0 011-1h4a1 1 0 011 1v14a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM14 5a1 1 0 011-1h4a1 1 0 011 1v6a1 1 0 01-1 1h-4a1 1 0 01-1-1V5zM14 17a1 1 0 011-1h4a1 1 0 011 1v2a1 1 0 01-1 1h-4a1 1 0 01-1-1v-2z" />
                    </svg>
                </a>

                <a href="{{ route('courses.index') }}" class="sidebar-item {{ request()->routeIs('courses.*') ? 'active' : '' }}" title="Courses">
                    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.232.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                    </svg>
                </a>

                <a href="{{ route('classes.index') }}" class="sidebar-item {{ request()->routeIs('classes.*') ? 'active' : '' }}" title="Classes">
                    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                    </svg>
                </a>

                <a href="{{ route('sessions.index') }}" class="sidebar-item {{ request()->routeIs('sessions.*') ? 'active' : '' }}" title="Sessions">
                    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg>
                </a>

                <a href="{{ route('files.index') }}" class="sidebar-item {{ request()->routeIs('files.*') ? 'active' : '' }}" title="Files">
                    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" />
                    </svg>
                </a>

            </div>

            <div class="sidebar-footer">
                <livewire:notifications />

                <button type="button" class="sidebar-item" title="Sign out" onclick="document.getElementById('logout-form').submit();">
                    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h6a2 2 0 012 2v1" />
                    </svg>
                </button>
            </div>

            <form id="logout-form" action="{{ route('logout') }}" method="POST" style="display:none;">
                @csrf
            </form>
        </div>


        <div class="main-layout">
            {{ $slot ?? '' }}
        </div>

        @livewireScripts
    </body>
</html>
