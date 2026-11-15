<?php

namespace App\Services;

use App\Models\Course;
use App\Models\StoreOrder;
use Illuminate\Support\Facades\DB;

class OnlinePurchaseFulfillment
{
    public static function fulfill(StoreOrder $order): void
    {
        DB::transaction(function () use ($order) {
            $o = StoreOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($o->fulfilled_at || $o->status !== 'paid') {
                return;
            }$user = $o->user;
            if ($o->kind === 'course') {
                $course = Course::find($o->product['course_id']);
                if (! $course || ! $course->published) {
                    throw new \RuntimeException('El curso pagado requiere revisión antes de activar el acceso.');
                }
            }
            if (! $user || ErpConnection::sync($user)['state'] !== 'connected' || $user->fresh()->erp_student_id !== $o->erp_student_id) {
                throw new \RuntimeException('La vinculación del comprador requiere revisión.');
            }$core = config('payments.erp_core');
            if (! $core || ! is_file($core)) {
                throw new \RuntimeException('Registro de compras no disponible.');
            }require_once $core;
            $prior = getenv('CEC_ACADEMIC_STORE_DIR');
            putenv('CEC_ACADEMIC_STORE_DIR='.dirname(config('erp.projection_path')));
            try {
                $result = \campus_record_paid_purchase(['id' => $o->id, 'campus_user_id' => $o->user_id, 'student_id' => $o->erp_student_id, 'payment_id' => $o->payment_id, 'buyer_name' => $o->buyer_name, 'buyer_email' => $o->buyer_email, 'payer_name' => $o->payer_name ?? '', 'payer_email' => $o->payer_email ?? '', 'paid_at' => $o->paid_at->toIso8601String(), 'product_name' => $o->product_name, 'method' => $o->method, 'kind' => $o->kind, 'product' => $o->product, 'amount' => $o->amount, 'currency' => $o->currency, 'live' => true]);
            } finally {
                $prior === false ? putenv('CEC_ACADEMIC_STORE_DIR') : putenv('CEC_ACADEMIC_STORE_DIR='.$prior);
            }$o->update(['status' => 'fulfilled', 'fulfilled_at' => now(), 'erp_result' => $result]);
            ErpConnection::sync($user);
        });
    }
}
