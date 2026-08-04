<?php

use Illuminate\Support\Facades\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component {
    #[Validate('required|string|email')]
    public string $email = '';

    public string $status = '';

    public function sendResetLink(): void
    {
        $this->validate();

        // An invitation that was never completed has no password to reset — those people
        // finish signing up instead, so they are answered like any unknown address.
        if (\App\Models\User::pendingInvites()->where('email', strtolower(trim($this->email)))->exists()) {
            $this->status = __(Password::RESET_LINK_SENT);
            $this->reset('email');

            return;
        }

        $status = Password::sendResetLink(['email' => $this->email]);

        // An unknown address is answered exactly like a known one, so the form can't be
        // used to find out who has an account here. Throttling still gets reported.
        if ($status !== Password::RESET_LINK_SENT && $status !== Password::INVALID_USER) {
            $this->addError('email', __($status));

            return;
        }

        $this->status = __(Password::RESET_LINK_SENT);
        $this->reset('email');
    }
}; ?>

<div>
    <h2 class="text-gray-500 text-sm mb-1">Forgot your password?</h2>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">Reset it with your email</h1>

    <p class="text-sm text-gray-600 mb-8">
        Enter the email you signed up with and we'll send you a link to choose a new password.
    </p>

    @if($status)
        <div class="mb-6 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm font-medium text-green-700">
            {{ $status }}
        </div>
    @endif

    <form wire:submit="sendResetLink" class="space-y-6">
        <div>
            <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email</label>
            <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                    <svg class="h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                    </svg>
                </div>
                <input wire:model="email" id="email" type="email" required autofocus
                       class="block w-full pl-10 pr-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500 sm:text-sm text-gray-900 placeholder-gray-400"
                       placeholder="Enter Your Registered Email">
            </div>
            @error('email') <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> @enderror
        </div>

        <button type="submit" class="w-full flex justify-center py-2.5 px-4 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-[#4353d8] hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors">
            <span wire:loading.remove wire:target="sendResetLink">Email Password Reset Link</span>
            <span wire:loading wire:target="sendResetLink">Sending…</span>
        </button>
    </form>

    <div class="mt-6 text-center">
        <p class="text-sm text-gray-600">
            Remembered it ? <a href="{{ route('login') }}" class="font-medium text-blue-600 hover:text-blue-500" wire:navigate>Back to login</a>
        </p>
    </div>
</div>
