<?php

namespace App\Http\Controllers;

use App\Services\TeacherDirectory;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class TeacherScheduleController extends Controller
{
    public function index(Request $r)
    {
        abort_unless($r->user()->isStaff(), 403);

        $d = $r->validate(['bono' => 'nullable|string|max:100', 'profesor' => 'nullable|integer|min:1']);

        return view('staff.schedule', ['preferredPass' => $d['bono'] ?? '', 'teacherId' => $r->user()->role === 'admin' ? (int) ($d['profesor'] ?? 0) : 0]);
    }

    public function data(Request $r)
    {
        $d = $r->validate(['month' => 'required|date_format:Y-m', 'teacher_id' => 'nullable|integer|min:1']);
        try {
            return response()->json(TeacherDirectory::agenda($r->user(), isset($d['teacher_id']) ? (int) $d['teacher_id'] : null, $d['month']));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function leave(Request $r)
    {
        $d = $r->validate(['action' => 'nullable|in:add,remove', 'id' => 'nullable|string|max:100', 'date' => 'required_unless:action,remove|date_format:Y-m-d', 'endDate' => 'nullable|date_format:Y-m-d', 'reason' => 'required_unless:action,remove|string|max:1000']);
        try {
            TeacherDirectory::leave($r->user(), $d);

            return response()->json(['ok' => true]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function booking(Request $r)
    {
        abort_unless($r->user()->isStaff(), 403);
        $d = $r->validate(['action' => 'required|in:create,update,cancel', 'pass_id' => 'required|string|max:100', 'revision' => 'required|integer|min:0', 'booking_id' => 'nullable|required_unless:action,create|string|max:100', 'date' => 'nullable|required_unless:action,cancel|date_format:Y-m-d', 'start' => 'nullable|required_unless:action,cancel|date_format:H:i', 'end' => 'nullable|required_unless:action,cancel|date_format:H:i', 'occurrences' => 'nullable|integer|min:1|max:52', 'reason' => 'nullable|required_if:action,cancel|string|max:1000']);
        try {
            return response()->json(TeacherDirectory::booking($r->user(), $d));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => __($e->getMessage())], 422);
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            report($e);

            return response()->json(['message' => __('No se pudo guardar el horario. Tus datos siguen en el formulario. Inténtalo de nuevo.')], 503);
        }
    }
}
