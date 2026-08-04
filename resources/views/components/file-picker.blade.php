@props([
    'model',            // Livewire property holding the selected file id
    'files' => [],      // File::pickerEntry() rows
    'live' => false,    // sync the property on every pick instead of on the next request
    'kind' => 'file',   // pdf | video | image — drives the icons, wording and what may be uploaded
    'placeholder' => null,
])

@php
    $placeholder ??= match ($kind) {
        'pdf' => 'Search your PDFs…',
        'video' => 'Search your videos…',
        'image' => 'Search your images…',
        default => 'Search your files…',
    };

    [$emptyLabel, $accept, $extensions, $extensionLabel] = match ($kind) {
        'pdf' => ['No PDFs uploaded yet', 'application/pdf,.pdf', ['pdf'], 'PDF'],
        'video' => ['No videos uploaded yet', 'video/*', ['mp4', 'mov', 'avi', 'webm', 'mkv', 'm4v'], 'video'],
        'image' => ['No images uploaded yet', 'image/*', ['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp'], 'image'],
        default => ['No files uploaded yet', '', [], 'file'],
    };

    $pickerId = 'fp-' . $model;
@endphp

@once
<style>
    .fp-trigger {
        width: 100%;
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 8px 10px;
        background: #fff;
        border: 1px solid #D1D5DB;
        border-radius: 10px;
        cursor: pointer;
        text-align: left;
        font: inherit;
        color: #111827;
        transition: border-color .15s ease, box-shadow .15s ease, background .15s ease;
    }
    .fp-trigger:hover { border-color: #A5B4FC; background: #FCFCFF; }
    .fp-trigger[aria-expanded="true"] {
        border-color: #6366F1;
        box-shadow: 0 0 0 3px rgba(99, 102, 241, .15);
    }
    .fp-root[data-dragging="true"] .fp-trigger {
        border-color: #6366F1;
        border-style: dashed;
        background: #EEF2FF;
    }
    .fp-thumb {
        width: 38px; height: 38px;
        flex-shrink: 0;
        border-radius: 8px;
        display: flex; align-items: center; justify-content: center;
        background: #EEF2FF; color: #4F46E5;
        overflow: hidden;
    }
    .fp-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .fp-thumb--muted { background: #F3F4F6; color: #9CA3AF; }
    .fp-name {
        font-size: 14px; font-weight: 500; color: #111827;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .fp-meta { font-size: 12px; color: #6B7280; margin-top: 1px; }
    .fp-clear {
        border: 0; background: transparent; padding: 4px; margin: 0;
        border-radius: 6px; cursor: pointer; color: #9CA3AF;
        display: flex; align-items: center;
    }
    .fp-clear:hover { background: #F3F4F6; color: #374151; }
    .fp-panel {
        position: absolute; left: 0; right: 0; z-index: 40;
        background: #fff;
        border: 1px solid #E5E7EB;
        border-radius: 12px;
        box-shadow: 0 12px 32px -8px rgba(17, 24, 39, .22), 0 4px 10px -4px rgba(17, 24, 39, .1);
        overflow: hidden;
        display: grid;
        grid-template-columns: minmax(0, 1.35fr) minmax(0, 1fr);
        min-width: min(480px, 92vw);
    }
    .fp-col-browse { min-width: 0; display: flex; flex-direction: column; }
    .fp-col-upload {
        min-width: 0; display: flex; flex-direction: column; gap: 10px;
        padding: 12px; background: #FAFAFB; border-left: 1px solid #F3F4F6;
    }
    .fp-col-title {
        font-size: 11px; font-weight: 700; letter-spacing: .04em;
        text-transform: uppercase; color: #9CA3AF;
    }
    @media (max-width: 560px) {
        .fp-panel { grid-template-columns: minmax(0, 1fr); }
        .fp-col-upload { border-left: 0; border-top: 1px solid #F3F4F6; }
    }
    .fp-search-row {
        display: flex; align-items: center; gap: 8px;
        padding: 10px 12px;
        border-bottom: 1px solid #F3F4F6;
        color: #9CA3AF;
    }
    .fp-search-row input {
        flex: 1; border: 0; outline: none; font: inherit; font-size: 14px;
        color: #111827; background: transparent; padding: 0;
    }
    .fp-list { flex: 1; max-height: 252px; overflow-y: auto; padding: 6px; }
    .fp-option {
        width: 100%;
        display: flex; align-items: center; gap: 10px;
        padding: 8px; border: 0; background: transparent;
        border-radius: 8px; cursor: pointer; text-align: left; font: inherit;
    }
    .fp-option[data-active="true"] { background: #EEF2FF; }
    .fp-option[data-selected="true"] .fp-name { color: #4338CA; }
    .fp-footer {
        display: flex; align-items: center; justify-content: space-between; gap: 8px;
        padding: 8px 10px; border-top: 1px solid #F3F4F6;
        background: #FAFAFB; font-size: 12px; color: #6B7280;
    }
    .fp-upload-btn {
        display: inline-flex; align-items: center; gap: 6px;
        border: 1px solid #C7D2FE; background: #EEF2FF; color: #4338CA;
        font: inherit; font-size: 12px; font-weight: 600;
        padding: 5px 10px; border-radius: 7px; cursor: pointer;
    }
    .fp-upload-btn:hover { background: #E0E7FF; }
    .fp-upload-btn:disabled { opacity: .6; cursor: default; }
    .fp-link { color: #4F46E5; font-weight: 600; text-decoration: none; }
    .fp-link:hover { text-decoration: underline; }
    .fp-empty { padding: 18px 16px; text-align: center; color: #6B7280; font-size: 13px; }
    .fp-dropzone {
        flex: 1; padding: 16px 12px; text-align: center;
        display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 6px;
        border: 1.5px dashed #C7D2FE; border-radius: 10px; background: #fff;
        color: #6B7280; font-size: 12px; cursor: pointer;
        transition: border-color .15s ease, background .15s ease;
    }
    .fp-dropzone:hover { border-color: #6366F1; background: #F5F3FF; }
    .fp-root[data-dragging="true"] .fp-dropzone { border-color: #4F46E5; background: #EEF2FF; }
    .fp-progress {
        display: flex; align-items: center; gap: 8px;
        font-size: 12px; color: #4338CA;
    }
    .fp-progress-track { flex: 1; height: 6px; border-radius: 999px; background: #E5E7EB; overflow: hidden; }
    .fp-progress-bar { height: 100%; background: #4F46E5; border-radius: 999px; transition: width .15s ease; }
    .fp-error {
        padding: 6px 8px; background: #FEF2F2; color: #B91C1C;
        font-size: 12px; border-radius: 6px;
    }
    .fp-kbd {
        font-size: 10px; font-weight: 600; color: #6B7280;
        background: #fff; border: 1px solid #E5E7EB; border-bottom-width: 2px;
        border-radius: 4px; padding: 1px 5px;
    }
</style>
@endonce

<div
    class="fp-root"
    :data-dragging="dragging"
    x-data="{
        open: false,
        query: '',
        active: 0,
        dropUp: false,
        dragging: false,
        uploading: false,
        progress: 0,
        uploadName: '',
        uploadError: '',
        endpoint: '{{ route('files.upload') }}',
        extensions: {{ Js::from($extensions) }},
        files: {{ Js::from(collect($files)->values()) }},
        selected: $wire.entangle('{{ $model }}'){{ $live ? '.live' : '' }},
        get filtered() {
            const q = this.query.trim().toLowerCase();
            if (!q) return this.files;
            const terms = q.split(/\s+/);
            return this.files.filter(f => terms.every(t => (f.name || '').toLowerCase().includes(t)));
        },
        get current() {
            if (this.selected === null || this.selected === '') return null;
            return this.files.find(f => String(f.id) === String(this.selected)) || null;
        },
        openPanel() {
            if (this.open) return this.closePanel();
            this.query = '';
            const rect = this.$el.getBoundingClientRect();
            this.dropUp = window.innerHeight - rect.bottom < 360 && rect.top > 360;
            this.open = true;
            this.active = Math.max(0, this.filtered.findIndex(f => String(f.id) === String(this.selected)));
            this.$nextTick(() => { this.$refs.search?.focus(); this.scrollActiveIntoView(); });
        },
        closePanel() {
            this.open = false;
            this.$nextTick(() => this.$refs.trigger?.focus());
        },
        move(step) {
            const total = this.filtered.length;
            if (!total) return;
            this.active = (this.active + step + total) % total;
            this.scrollActiveIntoView();
        },
        scrollActiveIntoView() {
            this.$nextTick(() => this.$refs.list?.querySelector('[data-active=true]')?.scrollIntoView({ block: 'nearest' }));
        },
        chooseActive() {
            const file = this.filtered[this.active];
            if (file) this.pick(file);
        },
        pick(file) {
            this.selected = String(file.id);
            this.closePanel();
        },
        clear() {
            this.selected = '';
            this.query = '';
        },
        browse() {
            this.$refs.uploader?.click();
        },
        isImage(file) {
            return !!file?.url && ['image', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp'].includes(file.type);
        },
        dropped(event) {
            this.dragging = false;
            const file = event.dataTransfer?.files?.[0];
            if (!file) return;
            if (!this.open) this.openPanel();
            this.upload(file);
        },
        upload(file) {
            if (!file || this.uploading) return;

            const extension = (file.name.split('.').pop() || '').toLowerCase();
            if (this.extensions.length && !this.extensions.includes(extension)) {
                this.uploadError = 'Only {{ $extensionLabel }} files can be added here.';
                return;
            }

            this.uploadError = '';
            this.uploading = true;
            this.uploadName = file.name;
            this.progress = 0;

            const body = new FormData();
            body.append('file', file);

            const request = new XMLHttpRequest();
            request.open('POST', this.endpoint);
            request.setRequestHeader('Accept', 'application/json');
            request.setRequestHeader('X-CSRF-TOKEN', document.querySelector('meta[name=csrf-token]').content);

            request.upload.addEventListener('progress', event => {
                if (event.lengthComputable) this.progress = Math.round((event.loaded / event.total) * 100);
            });

            request.addEventListener('load', () => {
                this.uploading = false;
                if (request.status < 200 || request.status >= 300) {
                    this.uploadError = this.errorFrom(request);
                    return;
                }
                const entry = JSON.parse(request.responseText);
                this.files = [entry, ...this.files.filter(f => String(f.id) !== String(entry.id))];
                this.query = '';
                this.pick(entry);
            });

            request.addEventListener('error', () => {
                this.uploading = false;
                this.uploadError = 'Network error — check your connection.';
            });

            request.send(body);
        },
        errorFrom(request) {
            try {
                return JSON.parse(request.responseText).message || 'Upload failed.';
            } catch (error) {
                return request.status === 413 ? 'File is too large to upload.' : 'Upload failed.';
            }
        },
    }"
    x-on:keydown.escape.window="open && closePanel()"
    @click.outside="open = false"
    x-on:dragover.prevent="dragging = true"
    x-on:dragleave.prevent="if (!$el.contains($event.relatedTarget)) dragging = false"
    x-on:drop.prevent="dropped($event)"
    style="position: relative;"
>
    <input type="file" x-ref="uploader" accept="{{ $accept }}" style="display: none;"
        x-on:change="upload($event.target.files[0]); $event.target.value = ''">

    <button type="button"
        x-ref="trigger"
        role="combobox"
        aria-haspopup="listbox"
        :aria-expanded="open"
        aria-controls="{{ $pickerId }}-list"
        class="fp-trigger"
        x-on:click="openPanel()"
        x-on:keydown.down.prevent="openPanel()"
    >
        <template x-if="isImage(current)">
            <span class="fp-thumb"><img :src="current.url" :alt="current.name"></span>
        </template>
        <template x-if="!isImage(current)">
            <span class="fp-thumb" :class="current ? '' : 'fp-thumb--muted'">
                <x-file-picker-icon :kind="$kind" />
            </span>
        </template>

        <span style="flex: 1; min-width: 0;">
            <span class="fp-name" style="display: block;"
                  :style="current ? '' : 'color: #6B7280; font-weight: 400;'"
                  x-text="dragging ? 'Drop to upload' : (current ? current.name : 'Choose a file')"></span>
            <span class="fp-meta" style="display: block;"
                  x-text="current
                        ? [current.size, current.uploaded].filter(Boolean).join(' · ')
                        : (files.length + ' file' + (files.length === 1 ? '' : 's') + ' available — search, drag in or upload')"></span>
        </span>

        <span x-show="current" x-cloak class="fp-clear" role="button" tabindex="-1" title="Clear selection"
              x-on:click.stop="clear()">
            <svg width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <path d="M5 5l10 10M15 5L5 15"/>
            </svg>
        </span>
        <svg width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="#9CA3AF" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round" style="flex-shrink: 0; transition: transform .15s ease;"
             :style="open ? 'transform: rotate(180deg);' : ''">
            <path d="M5 7.5L10 12.5L15 7.5"/>
        </svg>
    </button>

    <div x-show="open" x-cloak
        x-transition.opacity.duration.120ms
        class="fp-panel"
        :style="dropUp ? 'bottom: calc(100% + 6px);' : 'top: calc(100% + 6px);'"
    >
        <div class="fp-col-browse">
        <div class="fp-search-row">
            <svg width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <circle cx="9" cy="9" r="5.5"/><path d="M13.5 13.5L17 17"/>
            </svg>
            <input type="text"
                x-ref="search"
                x-model="query"
                x-on:input="active = 0"
                placeholder="{{ $placeholder }}"
                autocomplete="off"
                x-on:keydown.down.prevent="move(1)"
                x-on:keydown.up.prevent="move(-1)"
                x-on:keydown.enter.prevent="chooseActive()"
                x-on:keydown.escape.prevent.stop="closePanel()"
            >
            <span class="fp-kbd">esc</span>
        </div>

        <div class="fp-list" x-ref="list" id="{{ $pickerId }}-list" role="listbox">
            <template x-for="(file, index) in filtered" :key="file.id">
                <button type="button"
                    class="fp-option"
                    role="option"
                    :data-active="index === active"
                    :data-selected="String(file.id) === String(selected)"
                    :aria-selected="String(file.id) === String(selected)"
                    x-on:mouseenter="active = index"
                    x-on:click="pick(file)"
                >
                    <span class="fp-thumb" style="width: 34px; height: 34px;">
                        <template x-if="isImage(file)">
                            <img :src="file.url" :alt="file.name" loading="lazy">
                        </template>
                        <template x-if="!isImage(file)">
                            <x-file-picker-icon :kind="$kind" />
                        </template>
                    </span>
                    <span style="flex: 1; min-width: 0;">
                        <span class="fp-name" style="display: block;" x-text="file.name"></span>
                        <span class="fp-meta" style="display: block;"
                              x-text="[file.size, file.uploaded].filter(Boolean).join(' · ')"></span>
                    </span>
                    <svg x-show="String(file.id) === String(selected)" width="16" height="16" viewBox="0 0 20 20"
                         fill="none" stroke="#4F46E5" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M4.5 10.5L8 14L15.5 6.5"/>
                    </svg>
                </button>
            </template>

            <div x-show="filtered.length === 0" class="fp-empty">
                <template x-if="files.length === 0">
                    <span>{{ $emptyLabel }} — upload one to get started.</span>
                </template>
                <template x-if="files.length > 0">
                    <span>No files match “<span x-text="query"></span>”.</span>
                </template>
            </div>
        </div>

        <div class="fp-footer">
            <span x-text="filtered.length + ' of ' + files.length + ' shown'"></span>
            <a href="{{ route('files.index') }}" class="fp-link">File Manager</a>
        </div>
        </div>

        <div class="fp-col-upload">
            <span class="fp-col-title">Upload new</span>

            <div class="fp-dropzone" x-on:click="browse()">
                <svg width="22" height="22" viewBox="0 0 20 20" fill="none" stroke="#6366F1" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M10 13.5V4M6.5 7.5L10 4l3.5 3.5M3.5 14v1.5a1.5 1.5 0 0 0 1.5 1.5h10a1.5 1.5 0 0 0 1.5-1.5V14"/>
                </svg>
                <span style="font-weight: 600; color: #4338CA;" x-text="dragging ? 'Drop it here' : 'Drag &amp; drop a file'"></span>
                <span>or <span style="color: #4F46E5; font-weight: 600;">browse your computer</span></span>
                <span style="color: #9CA3AF;">{{ strtoupper($extensionLabel) }} · up to 20&nbsp;MB</span>
            </div>

            <div x-show="uploading" x-cloak class="fp-progress">
                <span style="max-width: 40%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" x-text="uploadName"></span>
                <span class="fp-progress-track"><span class="fp-progress-bar" :style="'width: ' + progress + '%'"></span></span>
                <span x-text="progress + '%'"></span>
            </div>

            <div x-show="uploadError" x-cloak class="fp-error" x-text="uploadError"></div>

            <button type="button" class="fp-upload-btn" style="justify-content: center;" x-on:click="browse()" :disabled="uploading">
                <svg width="13" height="13" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M10 14V4M6 8l4-4 4 4M4 15v1a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1v-1"/>
                </svg>
                <span x-text="uploading ? 'Uploading…' : 'Choose a file'"></span>
            </button>

            <p style="margin: 0; font-size: 11px; color: #9CA3AF; line-height: 1.4;">
                Uploaded files are saved to your File Manager and selected here straight away.
            </p>
        </div>
    </div>
</div>
