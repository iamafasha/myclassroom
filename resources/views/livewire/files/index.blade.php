<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Computed;
use Illuminate\Support\Facades\Storage;
use App\Models\File;

new #[Layout('layouts.app')] class extends Component {
    /** Active file type filter: 'all', 'other', or a key from typeGroups(). */
    public string $type = 'all';

    /** Search term matched against the file name. */
    public string $search = '';

    public function selectType(string $type)
    {
        $this->type = $type === 'all' || array_key_exists($type, $this->typeGroups()) ? $type : 'all';
    }

    public function clearFilters()
    {
        $this->reset(['type', 'search']);
    }

    /** Id of the file being renamed, if any. */
    public ?int $editingId = null;

    public string $editingName = '';

    public function startRename($fileId)
    {
        $file = File::ownedBy(auth()->user())->findOrFail($fileId);

        $this->resetErrorBag();
        $this->editingId = $file->id;
        $this->editingName = $file->name;
    }

    public function cancelRename()
    {
        $this->resetErrorBag();
        $this->reset(['editingId', 'editingName']);
    }

    public function rename()
    {
        $this->validate(
            ['editingName' => 'required|string|max:255'],
            ['editingName.required' => 'Please give the file a name.'],
        );

        $file = File::ownedBy(auth()->user())->findOrFail($this->editingId);

        // Only the display name changes — file_path stays put, so every link to this file keeps working.
        $file->update(['name' => trim($this->editingName)]);

        $this->cancelRename();
        $this->refreshFiles();

        session()->flash('success', 'File renamed successfully.');
    }

    public function delete($fileId)
    {
        $file = File::ownedBy(auth()->user())->findOrFail($fileId);

        if (Storage::disk('public')->exists($file->file_path)) {
            Storage::disk('public')->delete($file->file_path);
        }

        $file->delete();
        $this->refreshFiles();

        session()->flash('success', 'File deleted successfully.');
    }

    #[Computed]
    public function files()
    {
        $query = File::ownedBy(auth()->user())->search($this->search)->latest();

        if ($this->type === 'other') {
            $query->whereNotIn('file_type', $this->knownTypes());
        } elseif ($this->type !== 'all') {
            $query->whereIn('file_type', $this->typeGroups()[$this->type]['types']);
        }

        return $query->get();
    }

    /** How many files the user has per filter, keyed the same way as the filter buttons. */
    #[Computed]
    public function typeCounts()
    {
        $counts = File::ownedBy(auth()->user())
            ->search($this->search)
            ->selectRaw('file_type, count(*) as aggregate')
            ->groupBy('file_type')
            ->pluck('aggregate', 'file_type');

        $known = $this->knownTypes();

        $result = ['all' => $counts->sum()];

        foreach ($this->typeGroups() as $key => $group) {
            $result[$key] = $key === 'other'
                ? $counts->reject(fn ($count, $type) => in_array($type, $known))->sum()
                : $counts->only($group['types'])->sum();
        }

        return $result;
    }

    /** Filter definitions: label, the stored file_type values they cover, and badge colours. */
    public function typeGroups()
    {
        return [
            'image' => ['label' => 'Images', 'types' => ['image'], 'bg' => '#F5F3FF', 'color' => '#7C3AED'],
            'pdf' => ['label' => 'PDFs', 'types' => ['pdf'], 'bg' => '#FEF2F2', 'color' => '#DC2626'],
            'document' => ['label' => 'Documents', 'types' => ['word'], 'bg' => '#EFF6FF', 'color' => '#2563EB'],
            'spreadsheet' => ['label' => 'Spreadsheets', 'types' => ['excel'], 'bg' => '#ECFDF5', 'color' => '#059669'],
            'video' => ['label' => 'Videos', 'types' => ['video'], 'bg' => '#FFF7ED', 'color' => '#EA580C'],
            'audio' => ['label' => 'Audio', 'types' => ['audio'], 'bg' => '#FDF2F8', 'color' => '#DB2777'],
            'other' => ['label' => 'Other', 'types' => [], 'bg' => '#F3F4F6', 'color' => '#4B5563'],
        ];
    }

    /** Badge colours for a stored file_type value. */
    public function badgeStyle($fileType)
    {
        foreach ($this->typeGroups() as $group) {
            if (in_array($fileType, $group['types'])) {
                return $group;
            }
        }

        return $this->typeGroups()['other'];
    }

    private function knownTypes()
    {
        return collect($this->typeGroups())->pluck('types')->flatten()->all();
    }

    private function refreshFiles()
    {
        unset($this->files, $this->typeCounts);
    }
}; ?>

<div class="panel-list" style="width: 100%; padding: 40px; overflow-y: auto;">
    <div class="content-header">
        <div class="content-breadcrumb">Dashboard / Files</div>
        <h1 class="content-title">File Manager</h1>
    </div>

    @if (session('success'))
        <div style="background-color: #D1FAE5; color: #065F46; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
            {{ session('success') }}
        </div>
    @endif

    <div style="display: flex; gap: 30px; margin-top: 20px;"
         x-data="{
             queue: [],
             nextId: 1,
             selected: [],
             endpoint: '{{ route('files.upload') }}',
             get multiple() { return this.selected.length > 1 },
             pickFiles(event) {
                 let files = Array.from(event.target.files || []);
                 // Clear the name first, so the field is never disabled with stale text in it.
                 if (files.length > 1) { $refs.nameInput.value = '' }
                 this.selected = files.map(file => file.name);
             },
             startUploads() {
                 let files = Array.from($refs.fileInput.files || []);
                 if (! files.length) return;

                 let customName = files.length === 1 ? $refs.nameInput.value.trim() : '';

                 files.forEach(file => {
                     let item = { id: this.nextId++, file: file, customName: customName, name: customName || file.name, progress: 0, status: 'uploading', error: '' };
                     this.queue.push(item);
                     this.send(item);
                 });

                 // Free the picker straight away so more files can be queued while these are still going.
                 $refs.fileInput.value = '';
                 $refs.nameInput.value = '';
                 this.selected = [];
             },
             retry(item) {
                 item.status = 'uploading';
                 item.progress = 0;
                 item.error = '';
                 this.send(item);
             },
             send(item) {
                 let body = new FormData();
                 body.append('file', item.file);
                 if (item.customName) { body.append('name', item.customName) }

                 let request = new XMLHttpRequest();
                 request.open('POST', this.endpoint);
                 request.setRequestHeader('Accept', 'application/json');
                 request.setRequestHeader('X-CSRF-TOKEN', document.querySelector('meta[name=\'csrf-token\']').content);

                 request.upload.addEventListener('progress', event => {
                     if (event.lengthComputable) { item.progress = Math.round((event.loaded / event.total) * 100) }
                 });

                 request.addEventListener('load', () => {
                     if (request.status >= 200 && request.status < 300) {
                         item.progress = 100;
                         item.status = 'done';
                         this.refreshList();
                         setTimeout(() => this.dismiss(item.id), 2500);
                     } else {
                         item.status = 'failed';
                         item.error = this.errorFrom(request);
                     }
                 });

                 request.addEventListener('error', () => {
                     item.status = 'failed';
                     item.error = 'Network error — check your connection.';
                 });

                 request.send(body);
             },
             errorFrom(request) {
                 try {
                     let body = JSON.parse(request.responseText);
                     return body.message || 'Upload failed.';
                 } catch (error) {
                     return request.status === 413 ? 'File is too large to upload.' : 'Upload failed.';
                 }
             },
             refreshList() {
                 // One refresh per burst of finished uploads rather than one per file.
                 clearTimeout(this.refreshHandle);
                 this.refreshHandle = setTimeout(() => $wire.$refresh(), 250);
             },
             dismiss(id) { this.queue = this.queue.filter(item => item.id !== id) },
         }">
        <!-- Upload Form -->
        <div class="content-card" style="flex: 1; flex-direction: column; align-items: flex-start; align-self: flex-start;">
            <h2 style="margin-top: 0; font-size: 18px;">Upload New File</h2>

            <form x-on:submit.prevent="startUploads()" style="width: 100%; margin-top: 15px;">
                <div style="margin-bottom: 15px;">
                    <label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px;">File Name (Optional, single file only)</label>
                    <input type="text" name="name" maxlength="255" placeholder="Enter file name"
                           x-ref="nameInput"
                           x-bind:disabled="multiple"
                           x-bind:style="multiple
                               ? { background: '#F3F4F6', color: '#9CA3AF', cursor: 'not-allowed' }
                               : { background: '', color: '', cursor: '' }"
                           style="width: 100%; padding: 10px; border: 1px dashed #D1D5DB; border-radius: 8px;" />
                    <div x-show="multiple" x-cloak style="font-size: 12px; color: #6B7280; margin-top: 5px;">
                        Multiple files selected — each keeps its original name.
                    </div>
                </div>

                <div style="margin-bottom: 20px;">
                    <label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px;">Select Files</label>
                    <input type="file" multiple x-ref="fileInput" x-on:change="pickFiles($event)" style="width: 100%; padding: 10px; border: 1px dashed #D1D5DB; border-radius: 8px;">
                </div>

                <div x-show="selected.length" x-cloak style="margin-bottom: 15px; font-size: 13px; color: #4B5563;">
                    <strong>Ready to upload:</strong>
                    <ul style="margin: 6px 0 0; padding-left: 18px;">
                        <template x-for="fileName in selected" :key="fileName">
                            <li x-text="fileName"></li>
                        </template>
                    </ul>
                </div>

                <button type="submit" class="btn-solve" style="width: 100%; justify-content: center;"
                        x-bind:disabled="! selected.length"
                        x-bind:style="selected.length ? {} : { opacity: '0.5', cursor: 'not-allowed' }">
                    Upload Files
                </button>
            </form>
        </div>

        <!-- File List -->
        <div style="flex: 2;">
            <!-- Upload Queue: one row, one progress bar, one retry per file -->
            <div wire:ignore x-show="queue.length" x-cloak style="margin-bottom: 20px;">
                <div style="font-size: 13px; font-weight: 600; color: #4B5563; margin-bottom: 10px;">
                    Uploads (<span x-text="queue.filter(item => item.status === 'uploading').length"></span> in progress)
                </div>

                <template x-for="item in queue" :key="item.id">
                    <div style="background: #ffffff; border: 1px solid #E5E7EB; border-radius: 10px; padding: 14px 18px; margin-bottom: 10px;">
                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 15px;">
                            <div style="min-width: 0;">
                                <div style="font-size: 14px; font-weight: 600; color: #111827; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" x-text="item.name"></div>
                                <div style="font-size: 12px; margin-top: 3px;"
                                     x-bind:style="{ color: item.status === 'failed' ? '#DC2626' : (item.status === 'done' ? '#059669' : '#6B7280') }"
                                     x-text="item.status === 'failed' ? item.error : (item.status === 'done' ? 'Uploaded' : 'Uploading… ' + item.progress + '%')"></div>
                            </div>
                            <div style="display: flex; align-items: center; gap: 8px; flex-shrink: 0;">
                                <button type="button" x-show="item.status === 'failed'" x-on:click="retry(item)"
                                        style="background: #EFF6FF; color: #2563EB; border: none; padding: 7px 14px; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer;">
                                    Retry
                                </button>
                                <button type="button" x-show="item.status !== 'uploading'" x-on:click="dismiss(item.id)"
                                        style="background: #F3F4F6; color: #6B7280; border: none; padding: 7px 14px; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer;">
                                    Dismiss
                                </button>
                            </div>
                        </div>
                        <div style="height: 6px; background: #E5E7EB; border-radius: 4px; margin-top: 12px; overflow: hidden;">
                            <div x-bind:style="{
                                     width: item.progress + '%',
                                     background: item.status === 'failed' ? '#DC2626' : (item.status === 'done' ? '#059669' : '#4F46E5'),
                                 }"
                                 style="height: 100%; border-radius: 4px; transition: width 0.2s;"></div>
                        </div>
                    </div>
                </template>
            </div>

            <!-- Search -->
            <div style="position: relative; margin-bottom: 16px;">
                <svg width="16" height="16" fill="none" stroke="#9CA3AF" viewBox="0 0 24 24" style="position: absolute; left: 14px; top: 50%; transform: translateY(-50%);">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z"></path>
                </svg>
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search files by name..."
                       style="width: 100%; padding: 11px 14px 11px 40px; border-radius: 8px; border: 1px solid #D1D5DB; font-size: 13px; outline: none;">
            </div>

            <!-- Type Filters -->
            <div style="display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 20px;">
                @php($counts = $this->typeCounts)
                <button type="button" wire:click="selectType('all')"
                        style="border: 1px solid {{ $type === 'all' ? '#2563EB' : '#E5E7EB' }}; background: {{ $type === 'all' ? '#2563EB' : '#ffffff' }}; color: {{ $type === 'all' ? '#ffffff' : '#4B5563' }}; padding: 7px 14px; border-radius: 9999px; font-size: 13px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 7px; transition: all 0.15s;">
                    All Files
                    <span style="font-size: 11px; font-weight: 700; padding: 1px 7px; border-radius: 9999px; background: {{ $type === 'all' ? 'rgba(255,255,255,0.25)' : '#F3F4F6' }}; color: {{ $type === 'all' ? '#ffffff' : '#6B7280' }};">{{ $counts['all'] }}</span>
                </button>

                @foreach ($this->typeGroups() as $key => $group)
                    @continue($counts[$key] === 0 && $type !== $key)
                    <button type="button" wire:click="selectType('{{ $key }}')" wire:key="filter-{{ $key }}"
                            style="border: 1px solid {{ $type === $key ? $group['color'] : '#E5E7EB' }}; background: {{ $type === $key ? $group['color'] : '#ffffff' }}; color: {{ $type === $key ? '#ffffff' : '#4B5563' }}; padding: 7px 14px; border-radius: 9999px; font-size: 13px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 7px; transition: all 0.15s;">
                        {{ $group['label'] }}
                        <span style="font-size: 11px; font-weight: 700; padding: 1px 7px; border-radius: 9999px; background: {{ $type === $key ? 'rgba(255,255,255,0.25)' : $group['bg'] }}; color: {{ $type === $key ? '#ffffff' : $group['color'] }};">{{ $counts[$key] }}</span>
                    </button>
                @endforeach
            </div>

            <div class="contents-list">
                @forelse ($this->files as $file)
                    @php($style = $this->badgeStyle($file->file_type))
                    <div class="content-card" style="padding: 15px 20px;" wire:key="file-{{ $file->id }}">
                        <div class="content-info" style="min-width: 0;">
                            @if ($editingId === $file->id)
                                <form wire:submit="rename" style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                    <input type="text" wire:model="editingName" maxlength="255"
                                           wire:keydown.escape="cancelRename"
                                           x-init="$nextTick(() => $el.focus())"
                                           style="flex: 1; min-width: 200px; padding: 8px 10px; border: 1px solid #D1D5DB; border-radius: 6px; font-size: 14px; font-weight: 600;">
                                    <button type="submit" style="background: #2563EB; color: #ffffff; border: none; padding: 8px 15px; border-radius: 6px; font-weight: 600; cursor: pointer;">Save</button>
                                    <button type="button" wire:click="cancelRename" style="background: #F3F4F6; color: #6B7280; border: none; padding: 8px 15px; border-radius: 6px; font-weight: 600; cursor: pointer;">Cancel</button>
                                </form>
                                @error('editingName')
                                    <div style="color: #EF4444; font-size: 12px; margin-top: 5px;">{{ $message }}</div>
                                @enderror
                            @else
                                <div class="content-name">{{ $file->name }}</div>
                            @endif
                            <div class="content-details">
                                <span class="badge" style="background: {{ $style['bg'] }}; color: {{ $style['color'] }};">{{ strtoupper($file->file_type) }}</span>
                                <span>Uploaded {{ $file->created_at->diffForHumans() }}</span>
                                <span><a href="{{ asset('storage/' . $file->file_path) }}" target="_blank" style="color: #2563EB; text-decoration: none;">View File</a></span>
                            </div>
                        </div>
                        <div class="action-area" style="display: flex; align-items: center; gap: 8px;">
                            @if ($editingId !== $file->id)
                                <button type="button" wire:click="startRename({{ $file->id }})" title="Rename file"
                                        style="background: #EFF6FF; color: #2563EB; border: none; padding: 8px 15px; border-radius: 6px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 6px;">
                                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                    </svg>
                                    Rename
                                </button>
                            @endif
                            <button type="button" wire:click="delete({{ $file->id }})" wire:confirm="Are you sure you want to delete this file?"
                                    style="background: #FEE2E2; color: #EF4444; border: none; padding: 8px 15px; border-radius: 6px; font-weight: 600; cursor: pointer;">
                                Delete
                            </button>
                        </div>
                    </div>
                @empty
                    <div class="content-card" style="flex-direction: column; align-items: center; justify-content: center; padding: 40px; color: #6B7280; text-align: center; gap: 12px;">
                        @if ($type === 'all' && trim($search) === '')
                            <div>You have not uploaded any files yet.</div>
                        @else
                            <div>
                                No
                                {{ $type === 'all' ? 'files' : strtolower($this->typeGroups()[$type]['label']) }}
                                @if (trim($search) !== '')
                                    match "{{ $search }}".
                                @else
                                    to show.
                                @endif
                            </div>
                            <button type="button" wire:click="clearFilters"
                                    style="background: #EFF6FF; color: #2563EB; border: none; padding: 8px 16px; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer;">
                                Clear filters
                            </button>
                        @endif
                    </div>
                @endforelse
            </div>
        </div>
    </div>
</div>
