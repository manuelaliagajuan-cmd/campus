<?php

namespace App\Http\Controllers;

use App\Services\ClassroomConnectivity;
use Illuminate\Http\Request;

class ClassroomConnectivityController extends Controller
{
    public function edit(Request $r)
    {
        abort_unless($r->user()->role === 'admin', 403);

        return view('staff.connectivity', ['configured' => ClassroomConnectivity::configured()]);
    }

    public function test(Request $r)
    {
        abort_unless($r->user()->role === 'admin', 403);
        $servers = ClassroomConnectivity::servers('admin-check:'.$r->user()->id);

        return response()->json(['iceServers' => $servers, 'relayAvailable' => ClassroomConnectivity::hasRelay($servers)]);
    }

    public function update(Request $r)
    {
        abort_unless($r->user()->role === 'admin', 403);
        $data = $r->validate(['turn_key' => ['required', 'string', 'regex:/^[a-zA-Z0-9_-]{16,128}$/'], 'turn_token' => 'required|string|min:20|max:2000']);
        try {
            ClassroomConnectivity::configure($data['turn_key'], $data['turn_token']);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['turn_key' => $e->getMessage()]);
        }

        return back()->with('status', __('Servicio de conexión verificado y guardado. Las nuevas conexiones utilizarán el servicio.'));
    }
}
