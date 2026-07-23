@extends('layouts.app')

@section('content')
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
            <form action="{{ route('files.store') }}" method="POST" enctype="multipart/form-data" style="width: 100%; margin-top: 15px;">
                @csrf
                <div style="margin-bottom: 15px;">
                    <label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px;">File Name (Optional)</label>
                    <input type="text" name="name" class="select-styled" placeholder="Leave empty to use original name" style="background-image: none;">
                </div>
                
                <div style="margin-bottom: 20px;">
                    <label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px;">Select File</label>
                    <input type="file" name="file" required style="width: 100%; padding: 10px; border: 1px dashed #D1D5DB; border-radius: 8px;">
                    @error('file')
                        <div style="color: #EF4444; font-size: 12px; margin-top: 5px;">{{ $message }}</div>
                    @enderror
                </div>
                
                <button type="submit" class="btn-solve" style="width: 100%; justify-content: center;">
                    Upload File
                </button>
            </form>
        </div>

        <!-- File List -->
        <div style="flex: 2;">
            <div class="contents-list">
                @forelse ($files as $file)
                    <div class="content-card" style="padding: 15px 20px;">
                        <div class="content-info">
                            <div class="content-name">{{ $file->name }}</div>
                            <div class="content-details">
                                <span class="badge" style="background: #EFF6FF; color: #2563EB;">{{ strtoupper($file->file_type) }}</span>
                                <span>Uploaded {{ $file->created_at->diffForHumans() }}</span>
                                <span><a href="{{ asset('storage/' . $file->file_path) }}" target="_blank" style="color: #2563EB; text-decoration: none;">View File</a></span>
                            </div>
                        </div>
                        <div class="action-area">
                            <form action="{{ route('files.destroy', $file) }}" method="POST" onsubmit="return confirm('Are you sure you want to delete this file?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" style="background: #FEE2E2; color: #EF4444; border: none; padding: 8px 15px; border-radius: 6px; font-weight: 600; cursor: pointer;">
                                    Delete
                                </button>
                            </form>
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
@endsection
