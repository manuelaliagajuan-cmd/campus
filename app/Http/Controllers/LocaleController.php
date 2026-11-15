<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LocaleController extends Controller
{
    public function update(Request $r)
    {
        $d = $r->validate(['locale' => ['required', Rule::in(array_keys(config('locales')))]]);
        $r->session()->put('locale', $d['locale']);
        if ($u = $r->user()) {
            $u->locale = $d['locale'];
            $u->save();
        }

        return back();
    }
}
