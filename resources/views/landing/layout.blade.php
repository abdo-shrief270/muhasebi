<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $dir }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $tenant->name }} — {{ $tenant->tagline ?? 'محاسبي' }}</title>
    <meta name="description" content="{{ Str::limit($tenant->description ?? '', 160) }}">

    @if($tenant->favicon_path)
        <link rel="icon" href="{{ asset($tenant->favicon_path) }}" type="image/x-icon">
    @endif

    <!-- Open Graph -->
    <meta property="og:title" content="{{ $tenant->name }}">
    <meta property="og:description" content="{{ Str::limit($tenant->description ?? '', 160) }}">
    <meta property="og:type" content="website">
    @if($tenant->logo_path)
        <meta property="og:image" content="{{ asset($tenant->logo_path) }}">
    @endif

    <!-- Tailwind CSS -->
    @vite(['resources/css/app.css'])

    @php
        $primaryColor = preg_match('/^#[0-9a-fA-F]{3,6}$/', $tenant->primary_color ?? '') ? $tenant->primary_color : '#2c3e50';
        $secondaryColor = preg_match('/^#[0-9a-fA-F]{3,6}$/', $tenant->secondary_color ?? '') ? $tenant->secondary_color : '#3498db';
    @endphp
    <style>
        :root {
            --color-primary: {{ $primaryColor }};
            --color-secondary: {{ $secondaryColor }};
        }
    </style>

    <!-- Google Fonts for Arabic -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700&display=swap" rel="stylesheet">

    <style>
        body {
            font-family: 'Cairo', 'Segoe UI', Tahoma, sans-serif;
        }
        @if($tenant->custom_css)
            /* Tenant custom styles */
            @foreach($tenant->custom_css as $selector => $rules)
                @php
                    $safeSelector = preg_match('/^[a-zA-Z0-9\s\-\.#:,>+~\[\]]+$/', $selector) ? $selector : null;
                    $safeRules = preg_replace('/(<|>|javascript|expression|url\s*\()/i', '', $rules ?? '');
                @endphp
                @if($safeSelector)
                    {{ $safeSelector }} { {{ $safeRules }} }
                @endif
            @endforeach
        @endif
    </style>
</head>
<body class="bg-white text-gray-800 antialiased">
    @yield('content')

    @if($tenant->social_links)
        <footer class="py-6 text-center text-sm text-gray-500">
            <div class="flex justify-center gap-4">
                @foreach($tenant->social_links as $platform => $url)
                    @if(filter_var($url, FILTER_VALIDATE_URL) && str_starts_with($url, 'http'))
                        <a href="{{ $url }}" target="_blank" rel="noopener" class="hover:text-gray-700">{{ ucfirst($platform) }}</a>
                    @endif
                @endforeach
            </div>
        </footer>
    @endif
</body>
</html>
