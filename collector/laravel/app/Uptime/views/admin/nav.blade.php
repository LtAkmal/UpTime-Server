<ul class="nav nav-tabs" style="margin-bottom:15px">
    <li class="{{ request()->routeIs('admin.uptime') ? 'active' : '' }}"><a href="{{ route('admin.uptime') }}">Overview</a></li>
    <li class="{{ request()->routeIs('admin.uptime.incidents') ? 'active' : '' }}"><a href="{{ route('admin.uptime.incidents') }}">Incidents &amp; anomalies</a></li>
    <li class="{{ request()->routeIs('admin.uptime.settings') ? 'active' : '' }}"><a href="{{ route('admin.uptime.settings') }}">Settings</a></li>
    <li><a href="{{ route('uptime.status') }}" target="_blank" rel="noopener">Public status page <i class="fa fa-external-link"></i></a></li>
</ul>
