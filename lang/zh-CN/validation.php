<?php

return json_decode(file_get_contents(__DIR__.'/validation.json'), true, 512, JSON_THROW_ON_ERROR);
