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

    public function save()
    {
        $this->validate([
            'uploads' => 'required|array|min:1',
            'uploads.*' => 'file|max:20480',
            'name' => 'nullable|string|max:255',
        ], [
            'uploads.required' => 'Please choose at least one file to upload.',
            'uploads.*.max' => 'Each file must be 20MB or smaller.',
        ]);

        $single = count($this->uploads) === 1;

        foreach ($this->uploads as $upload) {
            $originalName = $upload->getClientOriginalName();

            File::create([
                'name' => $single && trim($this->name) !== '' ? trim($this->name) : $originalName,
                'file_path' => $upload->store('uploads', 'public'),
                'file_type' => $this->fileTypeFor($upload->getClientOriginalExtension()),
            ]);
        }

        $count = count($this->uploads);

        $this->reset(['uploads', 'name']);
        unset($this->files);

        session()->flash('success', $count . ' file' . ($count === 1 ? '' : 's') . ' uploaded successfully.');
    }

    public function delete($fileId)
    {
        $file = File::findOrFail($fileId);

        if (Storage::disk('public')->exists($file->file_path)) {
            Storage::disk('public')->delete($file->file_path);
        }

        $file->delete();
        unset($this->files);

        session()->flash('success', 'File deleted successfully.');
    }

    #[Computed]
    public function files()
    {
        return File::latest()->get();
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
                  x-data="{ progress: 0, uploading: false }"
                  x-on:livewire-upload-start="uploading = true; progress = 0"
                  x-on:livewire-upload-finish="uploading = false; progress = 100"
                  x-on:livewire-upload-cancel="uploading = false"
                  x-on:livewire-upload-error="uploading = false"
                  x-on:livewire-upload-progress="progress = $event.detail.progress"
                  style="width: 100%; margin-top: 15px;">

                <div style="margin-bottom: 15px;">
                    <label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px;">File Name (Optional, single file only)</label>
                    <input type="text" wire:model="name" style="width: 100%; padding: 10px; border: 1px dashed #D1D5DB; border-radius: 8px;" placeholder="Enter file name" />
                    @error('name')
                        <div style="color: #EF4444; font-size: 12px; margin-top: 5px;">{{ $message }}</div>
                    @enderror
                </div>

                <div style="margin-bottom: 20px;">
                    <label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px;">Select Files</label>
                    <input type="file" wire:model="uploads" multiple style="width: 100%; padding: 10px; border: 1px dashed #D1D5DB; border-radius: 8px;">
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
                        <div :style="`width: ${progress}%`" style="height: 8px; background: #4F46E5; border-radius: 4px; transition: width 0.2s;"></div>
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
            <div class="contents-list">
                @forelse ($this->files as $file)
                    <div class="content-card" style="padding: 15px 20px;" wire:key="file-{{ $file->id }}">
                        <div class="content-info">
                            <div class="content-name">{{ $file->name }}</div>
                            <div class="content-details">
                                <span class="badge" style="background: #EFF6FF; color: #2563EB;">{{ strtoupper($file->file_type) }}</span>
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
                    <div class="content-card" style="justify-content: center; padding: 40px; color: #6B7280;">
                        No files uploaded yet.
                    </div>
                @endforelse
            </div>
        </div>
    </div>
</div>
