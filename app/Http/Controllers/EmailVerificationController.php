<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\CampusAccessRegistry;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    public function send(Request $request)
    {
        if ($request->user()->hasVerifiedEmail()) {
            return back();
        }
        if (in_array(config('mail.default'), ['log', 'array'], true)) {
            return back()->with('status', __('Contacta con el centro para confirmar tu correo y conectar tus cursos y bonos.'));
        }
        $request->user()->sendEmailVerificationNotification();

        return back()->with('status', __('Te hemos enviado un enlace para confirmar tu correo.'));
    }

    public function verify(EmailVerificationRequest $request)
    {
        $request->fulfill();
        CampusAccessRegistry::safelyPublish($request->user());

        return redirect()->route('accesses')->with('status', __('Correo confirmado.'));
    }

    public function confirm(Request $request, User $user)
    {
        abort_unless($request->user()->role === 'admin', 403);
        $request->validate(['confirmed' => 'accepted']);
        if (! $user->hasVerifiedEmail()) {
            $user->forceFill(['email_verified_at' => now(), 'email_confirmed_by' => $request->user()->id])->save();
            event(new Verified($user));
            CampusAccessRegistry::safelyPublish($user);
        }

        return back()->with('status', __('Correo confirmado. Los accesos de Gestión se actualizarán al entrar.'));
    }
}
