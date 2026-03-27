<?php

use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Middleware\EnsureFirstUserRegistration;
use App\Http\Middleware\EnsureUsersExist;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::middleware('guest')->group(function () {
    Volt::route('login', 'pages.auth.login')
        ->middleware(EnsureUsersExist::class)
        ->name('login');

    Volt::route('register', 'pages.auth.register')
        ->middleware(EnsureFirstUserRegistration::class)
        ->name('register');

    Volt::route('forgot-password', 'pages.auth.forgot-password')
        ->middleware(EnsureUsersExist::class)
        ->name('password.request');

    Volt::route('reset-password/{token}', 'pages.auth.reset-password')
        ->middleware(EnsureUsersExist::class)
        ->name('password.reset');
});

Route::middleware('auth')->group(function () {
    Volt::route('verify-email', 'pages.auth.verify-email')
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Volt::route('confirm-password', 'pages.auth.confirm-password')
        ->name('password.confirm');
});
