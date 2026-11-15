<?php

function campus_teacher_profiles(): array
{
    return $GLOBALS['cec_teacher_profiles'] ?? [];
}
function campus_teacher_profile_update(string $id, string $email, array $values): void
{
    foreach ($GLOBALS['cec_teacher_profiles'] as &$p) {
        if ($p['id'] === $id && $p['email'] === $email) {
            $p = array_replace($p, $values);
        }
    }unset($p);
}
function campus_teacher_agenda(int $id, string $month): array
{
    $GLOBALS['cec_agenda_teacher'] = $id;

    return ['teacher' => ['id' => $id, 'name' => 'Teacher'], 'month' => $month, 'weekly' => [], 'events' => [], 'passes' => []];
}
function campus_teacher_leave(int $id, array $input): void
{
    $GLOBALS['cec_teacher_leave'] = ['teacher' => $id, 'input' => $input];
}
