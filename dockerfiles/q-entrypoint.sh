#!/bin/bash

# Horizon, net als productie: het serveert álle supervisors/queues uit
# config/horizon.php (default, bolcom, hordeuren, demunk, long) en dwingt de
# per-supervisor timeouts af. `exec` zodat PID 1 signalen ontvangt en een
# `docker stop` netjes via horizon:terminate afloopt.
exec php artisan horizon
