<?php

// Contract double for the separately deployed ERP. Its real writer is tested in GestionCEC.
function campus_record_paid_purchase(array $o): array
{
    $dir = getenv('CEC_ACADEMIC_STORE_DIR');
    $path = $dir.'/purchase-contract.json';
    $all = is_file($path) ? json_decode(file_get_contents($path), true) : [];
    if (isset($all[$o['id']])) {
        return $all[$o['id']];
    }$result = ['id' => $o['id'], 'receipt_id' => 'REC-1001', 'payment_id' => $o['payment_id']];
    $all[$o['id']] = $result;
    file_put_contents($path, json_encode($all));
    $source = json_decode(substr(file_get_contents($dir.'/campus.php'), 15), true);
    $source['revision']++;
    if ($o['kind'] === 'course') {
        $id = 'CAMPUS-'.$o['product']['course_id'];
        $source['courses'][] = ['id' => $id, 'name' => $o['product_name'], 'active' => true, 'campus_course_id' => $o['product']['course_id']];
        $source['students'][0]['courses'][] = $id;
    } else {
        $source['passes'][] = ['id' => 'BON-1', 'student_id' => $o['student_id'], 'name' => $o['product_name'], 'sessions' => $o['product']['sessions'], 'used' => 0, 'remaining' => $o['product']['sessions'], 'start' => '', 'expires' => '', 'online_enabled' => $o['product']['online_enabled'], 'bookings' => []];
    }
    file_put_contents($dir.'/campus.php', "<?php exit; ?>\n".json_encode($source));

    return $result;
}
