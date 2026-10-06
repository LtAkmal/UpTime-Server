<?php

return [
    // Blade layout for the public status pages. The default reuses the storefront's
    // header and footer. To use the minimal standalone layout, create config/uptime.php
    // in the panel with 'public_layout' => 'uptime::public.layout'.
    'public_layout' => 'storefront.layout',
];
