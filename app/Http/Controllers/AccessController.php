<?php

namespace App\Http\Controllers;

use App\Models\Course;
use Illuminate\Http\Request;

class AccessController extends Controller
{
    public function index(Request $request)
    {
        $courses = Course::accessibleTo($request->user())->with('teacher')->orderBy('title')->get();

        $erp = $request->attributes->get('erp', ['state' => 'unavailable', 'passes' => [], 'updated_at' => null]);

        return view('accesses', compact('courses', 'erp'));
    }
}
