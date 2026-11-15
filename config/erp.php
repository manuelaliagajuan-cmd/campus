<?php

return ['projection_path' => env('CEC_ERP_PROJECTION_PATH'), 'lesson_core' => env('CEC_ERP_LESSON_CORE', dirname(dirname(env('CEC_ERP_PROJECTION_PATH', '/unconfigured/private/campus.php'))).'/gestion/online-lessons-core.php'), 'teachers_core' => env('CEC_ERP_TEACHERS_CORE', dirname(dirname(env('CEC_ERP_PROJECTION_PATH', '/unconfigured/private/campus.php'))).'/gestion/teachers-core.php'), 'jobs_key' => env('CEC_LESSON_JOBS_KEY')];
