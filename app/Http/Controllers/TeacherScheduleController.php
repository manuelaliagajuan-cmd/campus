<?php

namespace App\Http\Controllers;

use App\Services\TeacherDirectory;
use Illuminate\Http\Request;

class TeacherScheduleController extends Controller
{
    public function index(Request $r)
    {
        abort_unless($r->user()->isStaff(), 403);

        return view('staff.schedule');
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
}
