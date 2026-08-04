<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Volt\Volt;

test('login offers a way to reach the forgot password screen', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSee('Forgot password?')
        ->assertSee(route('password.request'), false);
});

test('reset password link screen can be rendered', function () {
    $this->get('/forgot-password')
        ->assertOk()
        ->assertSeeVolt('auth.forgot-password')
        ->assertSee('Email Password Reset Link');
});

test('reset password link can be requested', function () {
    Notification::fake();

    $user = User::factory()->create();

    Volt::test('auth.forgot-password')
        ->set('email', $user->email)
        ->call('sendResetLink')
        ->assertHasNoErrors()
        ->assertSet('status', __(Password::RESET_LINK_SENT));

    Notification::assertSentTo($user, ResetPassword::class);
});

test('an unknown address is answered the same way without emailing anyone', function () {
    Notification::fake();

    Volt::test('auth.forgot-password')
        ->set('email', 'nobody@example.com')
        ->call('sendResetLink')
        ->assertHasNoErrors()
        ->assertSet('status', __(Password::RESET_LINK_SENT));

    Notification::assertNothingSent();
});

test('the email is validated before a link is sent', function () {
    Notification::fake();

    Volt::test('auth.forgot-password')
        ->set('email', 'not-an-email')
        ->call('sendResetLink')
        ->assertHasErrors(['email' => 'email']);

    Notification::assertNothingSent();
});

test('reset password screen can be rendered', function () {
    Notification::fake();

    $user = User::factory()->create();

    Volt::test('auth.forgot-password')
        ->set('email', $user->email)
        ->call('sendResetLink');

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
        $this->get('/reset-password/'.$notification->token)
            ->assertOk()
            ->assertSeeVolt('auth.reset-password')
            ->assertSee('Choose a new password');

        return true;
    });
});

test('password can be reset with valid token', function () {
    Notification::fake();

    $user = User::factory()->create();

    Volt::test('auth.forgot-password')
        ->set('email', $user->email)
        ->call('sendResetLink');

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
        Volt::test('auth.reset-password', ['token' => $notification->token])
            ->set('email', $user->email)
            ->set('password', 'new-secret-password')
            ->set('password_confirmation', 'new-secret-password')
            ->call('resetPassword')
            ->assertHasNoErrors()
            ->assertRedirect('/login');

        return true;
    });

    expect(Hash::check('new-secret-password', $user->fresh()->password))->toBeTrue();
});

test('a bad token leaves the password alone', function () {
    $user = User::factory()->create(['password' => Hash::make('original-password')]);

    Volt::test('auth.reset-password', ['token' => 'not-a-real-token'])
        ->set('email', $user->email)
        ->set('password', 'new-secret-password')
        ->set('password_confirmation', 'new-secret-password')
        ->call('resetPassword')
        ->assertHasErrors('email');

    expect(Hash::check('original-password', $user->fresh()->password))->toBeTrue();
});

test('the confirmation has to match', function () {
    $user = User::factory()->create();

    Volt::test('auth.reset-password', ['token' => 'any-token'])
        ->set('email', $user->email)
        ->set('password', 'new-secret-password')
        ->set('password_confirmation', 'something-else')
        ->call('resetPassword')
        ->assertHasErrors(['password' => 'confirmed']);
});

test('the unfinished phone and google buttons are hidden in production', function () {
    $this->get('/login')->assertSee('GOOGLE');
    $this->get('/register')->assertSee('GOOGLE');

    app()->detectEnvironment(fn () => 'production');

    $this->get('/login')
        ->assertOk()
        ->assertDontSee('GOOGLE')
        ->assertDontSee('USE PHONE')
        ->assertSee('Forgot password?');

    $this->get('/register')
        ->assertOk()
        ->assertDontSee('GOOGLE')
        ->assertDontSee('USE PHONE');
});
