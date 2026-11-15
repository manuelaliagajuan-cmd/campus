<?php

function campus_lesson_change(array $input): array
{
    return ($GLOBALS['cec_lesson_change'])($input);
}
function campus_pending_lesson_notices(): array
{
    return $GLOBALS['cec_pending_notices'] ?? [];
}
function campus_lesson_email_status(string $id, string $status): void
{
    $GLOBALS['cec_email_status'][$id] = $status;
}
