<?php

use App\Models\{File, User};
use Livewire\Livewire;

function sortableFiles(User $user): void
{
    File::create(['user_id' => $user->id, 'name' => 'Zebra Notes', 'file_path' => 'uploads/z.pdf', 'file_type' => 'pdf', 'size' => 2048, 'created_at' => now()->subDays(3)]);
    File::create(['user_id' => $user->id, 'name' => 'Alpha Photo', 'file_path' => 'uploads/a.png', 'file_type' => 'image', 'size' => 500, 'created_at' => now()->subDay()]);
    File::create(['user_id' => $user->id, 'name' => 'Middle Clip', 'file_path' => 'uploads/m.mp4', 'file_type' => 'video', 'size' => 900000, 'created_at' => now()->subDays(2)]);
}

function orderedNames($page): array
{
    return $page->instance()->files->pluck('name')->all();
}

it('defaults to newest first', function () {
    $user = User::factory()->create();
    sortableFiles($user);

    $page = Livewire::actingAs($user)->test('files.index');

    expect($page->get('sort'))->toBe('recent');
    expect(orderedNames($page))->toBe(['Alpha Photo', 'Middle Clip', 'Zebra Notes']);
});

it('sorts by name in both directions', function () {
    $user = User::factory()->create();
    sortableFiles($user);

    $page = Livewire::actingAs($user)->test('files.index')->call('selectSort', 'name');

    // Name starts A to Z rather than inheriting the previous column's direction.
    expect($page->get('direction'))->toBe('asc');
    expect(orderedNames($page))->toBe(['Alpha Photo', 'Middle Clip', 'Zebra Notes']);

    $page->call('selectSort', 'name');
    expect($page->get('direction'))->toBe('desc');
    expect(orderedNames($page))->toBe(['Zebra Notes', 'Middle Clip', 'Alpha Photo']);
});

it('sorts by size, largest first by default', function () {
    $user = User::factory()->create();
    sortableFiles($user);

    $page = Livewire::actingAs($user)->test('files.index')->call('selectSort', 'size');

    expect($page->get('direction'))->toBe('desc');
    expect(orderedNames($page))->toBe(['Middle Clip', 'Zebra Notes', 'Alpha Photo']);

    $page->call('toggleDirection');
    expect(orderedNames($page))->toBe(['Alpha Photo', 'Zebra Notes', 'Middle Clip']);
});

it('sorts files with no recorded size as the smallest', function () {
    $user = User::factory()->create();
    sortableFiles($user);
    File::create(['user_id' => $user->id, 'name' => 'Legacy File', 'file_path' => 'uploads/legacy.pdf', 'file_type' => 'pdf', 'size' => null]);

    $page = Livewire::actingAs($user)->test('files.index')->call('selectSort', 'size')->call('toggleDirection');

    expect(orderedNames($page))->toBe(['Legacy File', 'Alpha Photo', 'Zebra Notes', 'Middle Clip']);
});

it('sorts by type', function () {
    $user = User::factory()->create();
    sortableFiles($user);

    $page = Livewire::actingAs($user)->test('files.index')->call('selectSort', 'type');

    expect($page->get('direction'))->toBe('asc');
    // image, pdf, video
    expect(orderedNames($page))->toBe(['Alpha Photo', 'Zebra Notes', 'Middle Clip']);

    $page->call('selectSort', 'type');
    expect(orderedNames($page))->toBe(['Middle Clip', 'Zebra Notes', 'Alpha Photo']);
});

it('keeps sorting inside an active filter and search', function () {
    $user = User::factory()->create();
    sortableFiles($user);
    File::create(['user_id' => $user->id, 'name' => 'Alpha Second', 'file_path' => 'uploads/a2.png', 'file_type' => 'image', 'size' => 900]);

    $page = Livewire::actingAs($user)->test('files.index')
        ->call('selectType', 'image')
        ->set('search', 'alpha')
        ->call('selectSort', 'size');

    expect(orderedNames($page))->toBe(['Alpha Second', 'Alpha Photo']);
});

it('ignores an unknown sort column', function () {
    $user = User::factory()->create();
    sortableFiles($user);

    $page = Livewire::actingAs($user)->test('files.index')->call('selectSort', 'file_path; drop table files');

    expect($page->get('sort'))->toBe('recent');
    expect(orderedNames($page))->toBe(['Alpha Photo', 'Middle Clip', 'Zebra Notes']);
});

it('formats sizes for display', function () {
    $file = new File(['size' => 0]);
    expect($file->sizeForHumans())->toBe('0 B');

    expect((new File(['size' => 900]))->sizeForHumans())->toBe('900 B');
    expect((new File(['size' => 2048]))->sizeForHumans())->toBe('2 KB');
    expect((new File(['size' => 1572864]))->sizeForHumans())->toBe('1.5 MB');
    expect((new File(['size' => null]))->sizeForHumans())->toBeNull();
});
