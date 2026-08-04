<?php

use App\Models\{Classroom, Course, User};
use App\Notifications\ClassroomInvitation;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Livewire\Volt\Volt;

function seedInviteClass(): array
{
    $admin = User::factory()->create(['name' => 'Ada Admin']);
    $classroom = Classroom::create(['title' => 'Physics 101', 'admin_id' => $admin->id]);

    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics', 'created_by' => $admin->id]);
    $classroom->courses()->attach($course->id);

    return compact('admin', 'classroom', 'course');
}

it('stages typed and pasted addresses as chips', function () {
    ['admin' => $admin, 'classroom' => $classroom] = seedInviteClass();

    Livewire::actingAs($admin)->test('classes.show', ['classroom' => $classroom])
        ->set('inviteInput', 'One@Example.com')
        ->call('stageEmails')
        ->assertSet('inviteEmails', ['one@example.com'])
        ->assertSet('inviteInput', '')
        ->set('inviteInput', 'two@example.com, three@example.com; four@example.com')
        ->call('stageEmails')
        ->assertSet('inviteEmails', ['one@example.com', 'two@example.com', 'three@example.com', 'four@example.com'])
        // Duplicates are ignored rather than stacking up.
        ->set('inviteInput', 'two@example.com')
        ->call('stageEmails')
        ->assertSet('inviteEmails', ['one@example.com', 'two@example.com', 'three@example.com', 'four@example.com'])
        ->call('removeEmail', 'three@example.com')
        ->assertSet('inviteEmails', ['one@example.com', 'two@example.com', 'four@example.com']);
});

it('keeps invalid addresses in the box with an error', function () {
    ['admin' => $admin, 'classroom' => $classroom] = seedInviteClass();

    Livewire::actingAs($admin)->test('classes.show', ['classroom' => $classroom])
        ->set('inviteInput', 'good@example.com not-an-email')
        ->call('stageEmails')
        ->assertSet('inviteEmails', ['good@example.com'])
        ->assertSet('inviteInput', 'not-an-email')
        ->assertHasErrors('inviteInput');
});

it('adds people who already have an account', function () {
    Notification::fake();

    ['admin' => $admin, 'classroom' => $classroom] = seedInviteClass();
    $existing = User::factory()->create(['email' => 'member@example.com']);

    Livewire::actingAs($admin)->test('classes.show', ['classroom' => $classroom])
        ->set('inviteInput', 'member@example.com')
        ->call('sendInvites')
        ->assertHasNoErrors();

    expect($classroom->fresh()->hasMember($existing))->toBeTrue()
        ->and($existing->fresh()->isPendingInvite())->toBeFalse();

    Notification::assertSentTo($existing, ClassroomInvitation::class);
});

it('creates a partial account for someone without one and emails them', function () {
    Notification::fake();

    ['admin' => $admin, 'classroom' => $classroom] = seedInviteClass();

    Livewire::actingAs($admin)->test('classes.show', ['classroom' => $classroom])
        ->set('inviteInput', 'newcomer@example.com')
        ->call('sendInvites')
        ->assertSet('inviteEmails', [])
        ->assertHasNoErrors();

    $invited = User::where('email', 'newcomer@example.com')->first();

    expect($invited)->not->toBeNull()
        ->and($invited->isPendingInvite())->toBeTrue()
        ->and($invited->name)->toBeNull()
        ->and($invited->invited_by)->toBe($admin->id)
        ->and($invited->invited_at)->not->toBeNull()
        ->and($classroom->fresh()->hasMember($invited))->toBeTrue();

    Notification::assertSentTo($invited, ClassroomInvitation::class);
});

it('invites a whole pasted list at once and reports what happened', function () {
    Notification::fake();

    ['admin' => $admin, 'classroom' => $classroom] = seedInviteClass();
    $existing = User::factory()->create(['email' => 'member@example.com']);
    $classroom->users()->attach(User::factory()->create(['email' => 'already@example.com'])->id);

    Livewire::actingAs($admin)->test('classes.show', ['classroom' => $classroom])
        ->set('inviteInput', 'member@example.com, newcomer@example.com, already@example.com')
        ->call('sendInvites')
        ->assertHasNoErrors()
        ->assertSee('1 existing member added, 1 invitation sent, 1 already in this class.');

    expect($classroom->fresh()->users()->count())->toBe(3);
});

it('will not send with an empty box', function () {
    ['admin' => $admin, 'classroom' => $classroom] = seedInviteClass();

    Livewire::actingAs($admin)->test('classes.show', ['classroom' => $classroom])
        ->call('sendInvites')
        ->assertHasErrors('inviteInput');
});

it('keeps non-admins off the invite form', function () {
    ['classroom' => $classroom] = seedInviteClass();
    $outsider = User::factory()->create();

    Livewire::actingAs($outsider)->test('classes.show', ['classroom' => $classroom])
        ->assertForbidden();
});

it('shows pending invitations in the attendee list', function () {
    Notification::fake();

    ['admin' => $admin, 'classroom' => $classroom] = seedInviteClass();

    Livewire::actingAs($admin)->test('classes.show', ['classroom' => $classroom])
        ->set('inviteInput', 'newcomer@example.com')
        ->call('sendInvites')
        ->assertSee('newcomer@example.com')
        ->assertSee('Invited');
});

it('lets an invited person finish their account and keeps their class', function () {
    Notification::fake();

    ['admin' => $admin, 'classroom' => $classroom, 'course' => $course] = seedInviteClass();

    $classroom->inviteByEmail('newcomer@example.com', $admin);
    $invited = User::where('email', 'newcomer@example.com')->first();

    // The invitation link carries the address, so the form knows who is finishing up.
    $this->get(route('register', ['email' => 'newcomer@example.com']))
        ->assertOk()
        ->assertSee('Finish creating your account')
        ->assertSee('Physics 101');

    Volt::test('auth.register', ['email' => 'newcomer@example.com'])
        ->assertSet('email', 'newcomer@example.com')
        ->set('name', 'New Comer')
        ->set('password', 'a-good-password')
        ->set('password_confirmation', 'a-good-password')
        ->call('register')
        ->assertHasNoErrors()
        ->assertRedirect(route('home', absolute: false));

    $this->assertAuthenticated();

    $user = $invited->fresh();

    expect($user->id)->toBe($invited->id)
        ->and($user->name)->toBe('New Comer')
        ->and($user->isPendingInvite())->toBeFalse()
        ->and($user->invited_at)->toBeNull()
        ->and($classroom->hasMember($user))->toBeTrue()
        ->and(Course::visibleTo($user)->whereKey($course->id)->exists())->toBeTrue();
});

it('still rejects an email that belongs to a finished account', function () {
    $existing = User::factory()->create(['email' => 'taken@example.com']);

    Volt::test('auth.register')
        ->set('name', 'Impostor')
        ->set('email', 'taken@example.com')
        ->set('password', 'a-good-password')
        ->set('password_confirmation', 'a-good-password')
        ->call('register')
        ->assertHasErrors(['email' => 'unique']);

    expect(User::where('email', 'taken@example.com')->count())->toBe(1);
});

it('tells an invited person to finish signing up instead of logging in', function () {
    Notification::fake();

    ['admin' => $admin, 'classroom' => $classroom] = seedInviteClass();
    $classroom->inviteByEmail('newcomer@example.com', $admin);

    Volt::test('auth.login')
        ->set('email', 'newcomer@example.com')
        ->set('password', 'whatever-they-guessed')
        ->call('login')
        ->assertHasErrors('email');

    $this->assertGuest();
});

it('does not send a password reset to an unfinished invitation', function () {
    Notification::fake();

    ['admin' => $admin, 'classroom' => $classroom] = seedInviteClass();
    $classroom->inviteByEmail('newcomer@example.com', $admin);

    Volt::test('auth.forgot-password')
        ->set('email', 'newcomer@example.com')
        ->call('sendResetLink')
        ->assertHasNoErrors()
        ->assertSet('status', __(Illuminate\Support\Facades\Password::RESET_LINK_SENT));

    Notification::assertNotSentTo(
        User::where('email', 'newcomer@example.com')->first(),
        Illuminate\Auth\Notifications\ResetPassword::class
    );
});
