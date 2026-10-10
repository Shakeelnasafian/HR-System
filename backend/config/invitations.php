<?php
return [
    // Operational setting (not a business rule): how long an emailed invitation link stays valid; resend restarts it.
    'ttl_hours' => max(1, (int) env('INVITATION_TTL_HOURS', 72)),
];
