<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Computed;
use Livewire\WithFileUploads;
use Illuminate\Support\Facades\Storage;
use App\Models\File;

new #[Layout('layouts.app')] class extends Component {
    use WithFileUploads;

    public $uploads = [];

    public string $name = '';

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

    public function save()
    {
        $this->validate([
            'uploads' => 'required|array|min:1',
            'name' => 'nullable|string|max:255',
        ], [
            'uploads.required' => 'Please choose at least one file to upload.',
        ]);

        $single = count($this->uploads) === 1;

        foreach ($this->uploads as $upload) {
            $originalName = $upload->getClientOriginalName();

            File::create([
                'user_id' => auth()->id(),
                'name' => $single && trim($this->name) !== '' ? trim($this->name) : $originalName,
                'file_path' => $upload->store('uploads', 'public'),
                'file_type' => $this->fileTypeFor($upload->getClientOriginalExtension()),
            ]);
        }

        $count = count($this->uploads);

        $this->reset(['uploads', 'name']);
        $this->refreshFiles();

        // Let the form clear the native file input and its selection count.
        $this->dispatch('uploads-cleared');

        session()->flash('success', $count . ' file' . ($count === 1 ? '' : 's') . ' uploaded successfully.');
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

    private function fileTypeFor($extension)
    {
        $extension = strtolower($extension);

        return match (true) {
            in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'svg']) => 'image',
            $extension === 'pdf' => 'pdf',
            in_array($extension, ['doc', 'docx']) => 'word',
            in_array($extension, ['xls', 'xlsx']) => 'excel',
            in_array($extension, ['mp4', 'mov', 'avi']) => 'video',
            in_array($extension, ['mp3', 'wav']) => 'audio',
            default => $extension ?: 'other',
        };
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

    <div style="display: flex; gap: 30px; margin-top: 20px;">
        <!-- Upload Form -->
        <div class="content-card" style="flex: 1; flex-direction: column; align-items: flex-start; align-self: flex-start;">
            <h2 style="margin-top: 0; font-size: 18px;">Upload New File</h2>

            <form wire:submit="save"
                  x-data="{
                      progress: 0,
                      uploading: false,
                      fileCount: {{ count($uploads) }},
                      get multiple() { return this.fileCount > 1 },
                      countFiles(event) {
                          let count = event.target.files ? event.target.files.length : 0;
                          // Clear the name first, so the field is never disabled with stale text in it.
                          if (count > 1) {
                              $refs.nameInput.value = '';
                              $wire.set('name', '', false);
                          }
                          this.fileCount = count;
                      },
                  }"
                  x-on:livewire-upload-start="uploading = true; progress = 0"
                  x-on:livewire-upload-finish="uploading = false; progress = 100"
                  x-on:livewire-upload-cancel="uploading = false"
                  x-on:livewire-upload-error="uploading = false"
                  x-on:livewire-upload-progress="progress = $event.detail.progress"
                  x-on:uploads-cleared.window="fileCount = 0; $refs.fileInput.value = ''"
                  style="width: 100%; margin-top: 15px;">

                <div style="margin-bottom: 15px;">
                    <label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px;">File Name (Optional, single file only)</label>
                    <input type="text" wire:model="name" placeholder="Enter file name"
                           x-ref="nameInput"
                           x-bind:disabled="multiple"
                           x-bind:style="multiple
                               ? { background: '#F3F4F6', color: '#9CA3AF', cursor: 'not-allowed' }
                               : { background: '', color: '', cursor: '' }"
                           style="width: 100%; padding: 10px; border: 1px dashed #D1D5DB; border-radius: 8px;" />
                    <div x-show="multiple" x-cloak style="font-size: 12px; color: #6B7280; margin-top: 5px;">
                        Multiple files selected — each keeps its original name.
                    </div>
                    @error('name')
                        <div style="color: #EF4444; font-size: 12px; margin-top: 5px;">{{ $message }}</div>
                    @enderror
                </div>

                <div style="margin-bottom: 20px;">
                    <label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px;">Select Files</label>
                    <input type="file" wire:model="uploads" multiple x-ref="fileInput" x-on:change="countFiles($event)" style="width: 100%; padding: 10px; border: 1px dashed #D1D5DB; border-radius: 8px;">
                    @error('uploads')
                        <div style="color: #EF4444; font-size: 12px; margin-top: 5px;">{{ $message }}</div>
                    @enderror
                    @error('uploads.*')
                        <div style="color: #EF4444; font-size: 12px; margin-top: 5px;">{{ $message }}</div>
                    @enderror
                </div>

                <div x-show="uploading" style="display: none; margin-bottom: 15px;">
                    <div style="font-size: 13px; margin-bottom: 4px; color: #4B5563;">Uploading… <span x-text="progress + '%'"></span></div>
                    <div style="width: 100%; background: #E5E7EB; border-radius: 4px;">
                        <div :style="{ width: progress + '%' }" style="height: 8px; background: #4F46E5; border-radius: 4px; transition: width 0.2s;"></div>
                    </div>
                </div>

                @if($uploads)
                    <div style="margin-bottom: 15px; font-size: 13px; color: #4B5563;">
                        <strong>Ready to upload:</strong>
                        <ul style="margin: 6px 0 0; padding-left: 18px;">
                            @foreach($uploads as $upload)
                                <li>{{ $upload->getClientOriginalName() }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <button type="submit" class="btn-solve" style="width: 100%; justify-content: center;"
                        x-bind:disabled="uploading" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">Upload Files</span>
                    <span wire:loading wire:target="save">Saving…</span>
                </button>
            </form>
        </div>

        <!-- File List -->
        <div style="flex: 2;">
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
                        <div class="content-info">
                            <div class="content-name">{{ $file->name }}</div>
                            <div class="content-details">
                                <span class="badge" style="background: {{ $style['bg'] }}; color: {{ $style['color'] }};">{{ strtoupper($file->file_type) }}</span>
                                <span>Uploaded {{ $file->created_at->diffForHumans() }}</span>
                                <span><a href="{{ asset('storage/' . $file->file_path) }}" target="_blank" style="color: #2563EB; text-decoration: none;">View File</a></span>
                            </div>
                        </div>
                        <div class="action-area">
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
