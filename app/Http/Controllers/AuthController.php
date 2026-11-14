<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\CampusAccessRegistry;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AuthController extends Controller
{
    public function form(string $mode = 'login')
    {
        return view('auth', ['mode' => $mode]);
    }

    public function register(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|max:120', 'email' => 'required|email|max:190|unique:users,email', 'password' => ['required', 'confirmed', 'max:72', PasswordRule::min(12)->letters()->numbers()]]);
        $u = User::create($d);
        CampusAccessRegistry::safelyPublish($u);
        Auth::login($u);
        $r->session()->regenerate();

        return redirect()->route('dashboard');
    }

    public function login(Request $r)
    {
        $d = $r->validate(['email' => 'required|email', 'password' => 'required|string']);
        if (! Auth::attempt($d, $r->boolean('remember'))) {
            return back()->withErrors(['email' => __('No se ha podido iniciar sesión con esos datos.')])->onlyInput('email');
        }$r->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $r)
    {
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect()->route('login');
    }

    public function forgot(Request $r)
    {
        $r->validate(['email' => 'required|email']);
        if (config('mail.default') === 'log') {
            return back()->with('status', __('Para recuperar el acceso, contacta con el centro.'));
        }Password::sendResetLink($r->only('email'));

        return back()->with('status', __('Si existe una cuenta con ese correo, recibirás las instrucciones para recuperar el acceso.'));
    }

    public function resetForm(Request $r, string $token)
    {
        return view('auth', ['mode' => 'reset', 'token' => $token, 'email' => $r->query('email')]);
    }

    public function reset(Request $r)
    {
        $r->validate(['token' => 'required', 'email' => 'required|email', 'password' => ['required', 'confirmed', 'max:72', PasswordRule::min(12)->letters()->numbers()]]);
        $status = Password::reset($r->only('email', 'password', 'password_confirmation', 'token'), function (User $u, string $password) {
            $u->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
            event(new PasswordReset($u));
        });

        return $status === Password::PASSWORD_RESET ? redirect()->route('login')->with('status', __('Contraseña actualizada.')) : back()->withErrors(['email' => __('El enlace no es válido o ha caducado.')]);
    }
}
