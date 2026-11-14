<?php

return ['ice_servers' => json_decode(env('CEC_RTC_ICE_SERVERS', '[{"urls":"stun:stun.l.google.com:19302"}]'), true) ?: [], 'max_participants' => 6];
