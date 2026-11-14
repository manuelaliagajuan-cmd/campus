<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class LocaleController extends Controller
{
    public function update(Request $r)
    {
        $d = $r->validate(['locale' => 'required|in:es,en']);
        $r->session()->put('locale', $d['locale']);
        if ($u = $r->user()) {
            $u->locale = $d['locale'];
            $u->save();
        }

return back();
    }
}
