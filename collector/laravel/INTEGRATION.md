# Integrating the collector into a Pterodactyl panel

The collector is self-contained in `app/Uptime` (code, migration, views, stylesheet,
routes). The panel needs one required change and two optional links.

## Required: register the service provider

In `config/app.php`, in the `providers` list (or run `install.sh … --register`):

```php
Pterodactyl\Uptime\UptimeServiceProvider::class,
```

The provider registers its routes before the panel's own routes (so the React
catch-all never takes `/status`), its views (`uptime::`), migration, rate limiters,
Artisan commands and schedule (`uptime:check` every minute, `uptime:verify-chain --all
--record` hourly). Then:

```bash
php artisan migrate --force && php artisan optimize:clear
```

## Optional: admin sidebar link

In `resources/views/layouts/admin.blade.php`, inside `<ul class="sidebar-menu">`:

```blade
<li class="header">MONITORING</li>
<li class="{{ ! starts_with(Route::currentRouteName(), 'admin.uptime') ?: 'active' }}">
    <a href="{{ route('admin.uptime') }}"><i class="fa fa-heartbeat"></i> <span>Uptime Monitoring</span></a>
</li>
```

## Optional: public link

Anywhere in a public layout, for example a footer:

```blade
@if(Route::has('uptime.status'))<a href="{{ route('uptime.status') }}">System status</a>@endif
```

## Layout

The public pages extend `storefront.layout` (sections `title`, `description`, `head`,
`content`). Panels without that layout can use the bundled minimal layout by creating
`config/uptime.php`:

```php
<?php return ['public_layout' => 'uptime::public.layout'];
```

## Requirements

PHP 8.3 with the sodium extension, MySQL or MariaDB (triggers need the `TRIGGER`
privilege; without it the migration fails rather than silently skipping the safeguard),
the panel scheduler running every minute.

## Uninstall

Remove the provider line, then `php artisan migrate:rollback --path=app/Uptime/migrations`
only if you really want to delete all monitoring evidence, and remove `app/Uptime`.
