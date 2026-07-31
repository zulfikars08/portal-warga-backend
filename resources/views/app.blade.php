<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · Portal Warga</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">
    <header class="border-b bg-white">
        <nav class="mx-auto flex max-w-6xl items-center justify-between px-4 py-4" aria-label="Navigasi utama">
            <a href="/" class="font-semibold">Portal Warga</a>
            <div class="flex gap-4 text-sm">
                <a href="/roles-permissions" class="hover:text-indigo-600">Role & Izin</a>
                <a href="/settings" class="hover:text-indigo-600">Pengaturan</a>
            </div>
        </nav>
    </header>
    <main id="app" data-page="{{ $page }}" class="mx-auto max-w-6xl p-4 sm:p-8"></main>
</body>
</html>
