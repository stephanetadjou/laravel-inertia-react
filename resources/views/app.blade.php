<html>
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#2B1A3D">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        <link rel="manifest" href="/manifest.webmanifest">
        {{-- Applique le thème mémorisé (clé "theme" de ThemeContext) avant le premier affichage, pour éviter un flash clair en mode sombre. --}}
        <script>
            try {
                if (localStorage.getItem('theme') === 'dark') {
                    document.documentElement.classList.add('dark');
                }
            } catch (e) {}
        </script>
        @viteReactRefresh
        @vite(['resources/js/app.tsx','resources/css/app.css'])
        <x-inertia::head>
            <title>{{ config('app.name', 'ClientlyX') }}</title>
        </x-inertia::head>
    </head>
    <body>
        <x-inertia::app />
    </body>
</html>