<?php

return ['stripe_key' => env('STRIPE_SECRET_KEY'), 'stripe_webhook' => env('STRIPE_WEBHOOK_SECRET'), 'erp_core' => env('CEC_ERP_PURCHASE_CORE', dirname(dirname(env('CEC_ERP_PROJECTION_PATH', '/unconfigured/private/campus.php'))).'/gestion/online-purchases-core.php')];
