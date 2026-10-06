{{-- Minimal standalone layout: set config('uptime.public_layout') to 'uptime::public.layout' to use it. --}}
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>@yield('title') · {{ config('app.name') }}</title>
        <meta name="description" content="@yield('description')">
        <meta name="theme-color" content="#0a0e15">
        @yield('head')
        <style>body{margin:0;background:#0a0e15;color:#e7ebf1;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}</style>
    </head>
    <body>
        <main id="main">@yield('content')</main>
    </body>
</html>
