<?php

namespace App\Notifications;

use App\Models\Classroom;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ClassroomInvitation extends Notification
{
    use Queueable;

    public function __construct(
        public Classroom $classroom,
        public User $invitedBy,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $className = $this->classroom->title;
        $inviter = $this->invitedBy->displayName();

        // People who already have an account land in the class; invited people are sent to
        // registration with their email filled in, which turns the invite into a real account.
        $pending = $notifiable instanceof User && $notifiable->isPendingInvite();

        $mail = (new MailMessage())
            ->subject($inviter . ' added you to ' . $className)
            ->greeting($pending ? 'Hello!' : 'Hello ' . $notifiable->displayName() . '!')
            ->line($inviter . ' added you to the class "' . $className . '" on ' . config('app.name') . '.');

        if ($pending) {
            return $mail
                ->line('An account has been started for you — set your name and a password to finish it, and the class will be waiting for you.')
                ->action('Finish creating your account', route('register', ['email' => $notifiable->email]))
                ->line('If you were not expecting this invitation you can ignore this email.');
        }

        return $mail
            ->action('Open your dashboard', route('home'))
            ->line('Its courses are waiting for you there.');
    }
}
