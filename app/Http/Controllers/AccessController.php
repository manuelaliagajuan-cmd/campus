<?php

namespace App\Http\Controllers;

use App\Models\Course;
use Illuminate\Http\Request;

class AccessController extends Controller
{
    public function index(Request $request)
    {
        $courses = Course::accessibleTo($request->user())->with('teacher')->orderBy('title')->get();

        return view('accesses', compact('courses'));
    }
}
