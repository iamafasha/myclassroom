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
                <form id="upload-form" action="{{ route('files.store') }}" method="POST" enctype="multipart/form-data" style="width: 100%; margin-top: 15px;">
                    @csrf
                    <div style="margin-bottom: 15px;">
                        <label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px;">File Name (Optional)</label>
    
                    </div>
                    
                    <div style="margin-bottom: 20px;">
                        <label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px;">Select Files</label>
                        <input type="file" name="files[]" multiple required style="width: 100%; padding: 10px; border: 1px dashed #D1D5DB; border-radius: 8px;">
                        @error('files')
                            <div style="color: #EF4444; font-size: 12px; margin-top: 5px;">{{ $message }}</div>
                        @enderror
                    </div>
                    
                    <button type="submit" class="btn-solve" style="width: 100%; justify-content: center;">
                        Upload Files
                    </button>
                </form>
<div id="upload-progress" style="margin-top: 20px;"></div>
            <script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('upload-form');
    const progressContainer = document.getElementById('upload-progress');

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        progressContainer.innerHTML = '';
        const fileInput = form.querySelector('input[name="files[]"]');
        const files = fileInput.files;
        const nameField = form.querySelector('input[name="name"]');
        const baseName = nameField ? nameField.value.trim() : '';

        Array.from(files).forEach((file, index) => {
            const formData = new FormData();
            formData.append('files[]', file);
            // If a base name is provided, use it with an index suffix, otherwise let server use original name
            if (baseName) {
                formData.append('name[' + index + ']', baseName + '_' + (index + 1));
            }

            const xhr = new XMLHttpRequest();
            const progressBar = document.createElement('div');
            progressBar.style.width = '100%';
            progressBar.style.background = '#E5E7EB';
            progressBar.style.borderRadius = '4px';
            progressBar.style.marginTop = '8px';
            const progressFill = document.createElement('div');
            progressFill.style.width = '0%';
            progressFill.style.height = '8px';
            progressFill.style.background = '#3B82F6';
            progressFill.style.borderRadius = '4px';
            progressBar.appendChild(progressFill);
            const label = document.createElement('div');
            label.textContent = 'Uploading ' + file.name;
            label.style.fontSize = '14px';
            label.style.marginBottom = '4px';
            progressContainer.appendChild(label);
            progressContainer.appendChild(progressBar);

            xhr.upload.addEventListener('progress', function (e) {
                if (e.lengthComputable) {
                    const percent = Math.round((e.loaded / e.total) * 100);
                    progressFill.style.width = percent + '%';
                }
            });

            xhr.onreadystatechange = function () {
                if (xhr.readyState === XMLHttpRequest.DONE) {
                    if (xhr.status === 302 || xhr.status === 200) {
                        label.textContent = file.name + ' uploaded successfully.';
                        progressFill.style.background = '#10B981'; // green
                    } else {
                        label.textContent = 'Error uploading ' + file.name;
                        progressFill.style.background = '#EF4444'; // red
                    }
                }
            };

            xhr.open('POST', form.action, true);
            const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
            xhr.setRequestHeader('X-CSRF-TOKEN', csrfToken);
            xhr.send(formData);
        });
    });
});
</script>
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
