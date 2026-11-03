<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Horsefly\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     *
     * @return \Illuminate\View\View
     */
    public function create()
    {
        return view('auth.signup');
    }

    /**
     * Handle an incoming registration request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|confirmed|min:8',
        ]);

        // New accounts wait for an administrator: inactive, no role, not logged in. Login
        // (LoginController) refuses inactive users and applies the IP allowlist, so a
        // sign-up can no longer bypass either. Admins activate them in Users -> Mark as Active.
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'is_active' => 0,
            'is_admin' => 0,
        ]);

        event(new Registered($user));

        return redirect()->route('login')->withErrors([
            'approval' => 'Your account has been created and is waiting for an administrator to approve it.',
        ]);
    }
}
