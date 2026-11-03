@extends('layouts.auth', ['title' => 'No access yet'])

{{-- Shown after sign-in to an account that has no role or permission yet (e.g. a sign-up an
     administrator has approved but not given a role). PermissionHelper::firstAllowedRoute sends
     such users here. --}}
@section('content')
    <div class="col-xl-5 col-lg-5 col-md-8">
        <div class="card auth-card">
            <div class="card-body px-3 py-5">
                <div class="mx-auto mb-4 text-center auth-logo">
                    <img src="{{ asset('images/KingsburyPersonnel_logo_row.webp') }}" height="80" alt="crm">
                </div>

                <h2 class="fw-bold text-uppercase text-center fs-18">No access yet</h2>
                <p class="text-muted text-center mt-1 mb-4">
                    Your account ({{ auth()->user()->email }}) is signed in but has no role yet.
                    Ask an administrator to give you a role, then sign in again.
                </p>

                <div class="px-4">
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <div class="mb-1 text-center d-grid">
                            <button class="btn btn-primary py-2" type="submit">Sign out</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
