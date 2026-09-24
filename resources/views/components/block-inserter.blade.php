@props([
    'after' => null,        // content id the new block goes after; null adds it at the end
    'variant' => 'bar',     // bar: always-open drop zone under the blocks | between: "+" line between two blocks
])

{{--
    Adds a block in one action from the reading page: drop or pick a file, paste a link,
    or type a note. The type comes from what was added. The other types open their form
    in place. Talks to the surrounding content-show component through $wire.
--}}

@once
<style>
    .bi-between { position: relative; height: 30px; margin: 6px 0; }
    .bi-between-line {
        position: absolute; left: 0; right: 0; top: 50%; height: 2px; margin-top: -1px;
        background: #C7D2FE; opacity: 0; transition: opacity .15s ease;
    }
    .bi-between:hover .bi-between-line, .bi-between:focus-within .bi-between-line,
    .bi-between[data-open="true"] .bi-between-line, .bi-root[data-dragging="true"] .bi-between-line { opacity: 1; }
    .bi-plus {
        position: absolute; left: 50%; top: 50%; transform: translate(-50%, -50%);
        width: 26px; height: 26px; border-radius: 999px; border: 1px solid #C7D2FE;
        background: #fff; color: #4F46E5; cursor: pointer; display: flex; align-items: center; justify-content: center;
        opacity: 0; transition: opacity .15s ease, background .15s ease;
        font-size: 16px; line-height: 1; padding: 0;
    }
    .bi-between:hover .bi-plus, .bi-plus:focus-visible, .bi-root[data-dragging="true"] .bi-plus { opacity: 1; }
    .bi-plus:hover { background: #EEF2FF; }

    .bi-panel {
        border: 1.5px dashed #C7D2FE; border-radius: 10px; background: #FAFAFF;
        padding: 12px; display: flex; flex-direction: column; gap: 10px;
        transition: border-color .15s ease, background .15s ease;
    }
    .bi-root[data-dragging="true"] .bi-panel { border-color: #4F46E5; background: #EEF2FF; }
    .bi-row { display: flex; gap: 8px; align-items: flex-start; }
    .bi-input {
        flex: 1; min-width: 0; resize: none; font: inherit; font-size: 14px; line-height: 1.4;
        border: 1px solid #D1D5DB; border-radius: 8px; padding: 8px 10px; background: #fff; outline: none;
        max-height: 200px;
    }
    .bi-input:focus { border-color: #6366F1; box-shadow: 0 0 0 3px rgba(99, 102, 241, .15); }
    .bi-btn {
        display: inline-flex; align-items: center; gap: 6px; white-space: nowrap;
        border: 1px solid #D1D5DB; background: #fff; color: #374151;
        font: inherit; font-size: 13px; font-weight: 600; padding: 8px 12px; border-radius: 8px; cursor: pointer;
    }
    .bi-btn:hover { background: #F9FAFB; }
    .bi-btn:disabled { opacity: .6; cursor: default; }
    .bi-btn--primary { background: #4F46E5; border-color: #4F46E5; color: #fff; }
    .bi-btn--primary:hover { background: #4338CA; }
    .bi-hint { font-size: 12px; color: #6B7280; }
    .bi-types { display: flex; flex-wrap: wrap; gap: 6px; }
    .bi-type {
        border: 1px solid #E5E7EB; background: #fff; color: #374151; border-radius: 999px;
        font: inherit; font-size: 12px; font-weight: 500; padding: 4px 10px; cursor: pointer;
    }
    .bi-type:hover { border-color: #A5B4FC; color: #4338CA; }
    .bi-progress { display: flex; align-items: center; gap: 8px; font-size: 12px; color: #4338CA; }
    .bi-track { flex: 1; height: 6px; border-radius: 999px; background: #E5E7EB; overflow: hidden; }
    .bi-bar { height: 100%; background: #4F46E5; transition: width .15s ease; }
    .bi-error { font-size: 12px; color: #B91C1C; background: #FEF2F2; border-radius: 6px; padding: 6px 8px; }
    /* Between-block inserters are a desktop affordance; phones use the bar under the blocks. */
    @media (max-width: 820px) {
        .bi-between { display: none; }
        .bi-row { flex-wrap: wrap; }
        .bi-row .bi-input { flex-basis: 100%; }
    }
</style>
@endonce

<div
    class="bi-root {{ $variant === 'between' ? 'bi-between' : '' }}"
    :data-dragging="dragging"
    :data-open="open"
    x-data="{
        after: {{ Js::from($after) }},
        endpoint: '{{ route('files.upload') }}',
        open: {{ $variant === 'bar' ? 'true' : 'false' }},
        collapsible: {{ $variant === 'between' ? 'true' : 'false' }},
        text: '',
        busy: false,
        dragging: false,
        progress: 0,
        uploadName: '',
        error: '',
        isFileDrag(event) {
            return [...(event.dataTransfer?.types || [])].some(t => t === 'Files' || t === 'text/uri-list');
        },
        show() {
            this.open = true;
            this.$nextTick(() => this.$refs.input?.focus());
        },
        close() {
            if (this.collapsible) this.open = false;
            this.text = '';
            this.error = '';
        },
        grow() {
            const el = this.$refs.input;
            if (!el) return;
            el.style.height = 'auto';
            el.style.height = el.scrollHeight + 'px';
        },
        submit() {
            const value = this.text.trim();
            if (!value || this.busy) return;
            this.busy = true;
            this.error = '';
            $wire.addFromPaste(value, this.after).then((problem) => {
                this.busy = false;
                // A rejected link stays in the box so it can be fixed.
                if (problem) {
                    this.error = problem;
                    return;
                }
                this.text = '';
                this.$nextTick(() => this.grow());
                this.close();
            }).catch(() => { this.busy = false; });
        },
        pasted(event) {
            const file = event.clipboardData?.files?.[0];
            if (file) {
                event.preventDefault();
                this.upload(file);
                return;
            }
            // A lone link is added straight away; other text lands in the box to be edited first.
            const pastedText = (event.clipboardData?.getData('text') || '').trim();
            if (this.text.trim() === '' && /^https?:\/\/\S+$/i.test(pastedText)) {
                event.preventDefault();
                this.text = pastedText;
                this.submit();
            }
        },
        dropped(event) {
            this.dragging = false;
            const file = event.dataTransfer?.files?.[0];
            if (file) {
                this.upload(file);
                return;
            }
            const link = (event.dataTransfer?.getData('text/uri-list') || event.dataTransfer?.getData('text/plain') || '').trim();
            if (link) {
                this.text = link.split(/\s+/)[0];
                this.submit();
            }
        },
        upload(file) {
            if (!file || this.busy) return;
            this.open = true;
            this.busy = true;
            this.error = '';
            this.progress = 0;
            this.uploadName = file.name;

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
                if (request.status < 200 || request.status >= 300) {
                    this.busy = false;
                    this.error = this.errorFrom(request);
                    return;
                }
                const entry = JSON.parse(request.responseText);
                $wire.addFromFile(entry.id, this.after).then(() => {
                    this.busy = false;
                    this.uploadName = '';
                    this.close();
                });
            });
            request.addEventListener('error', () => {
                this.busy = false;
                this.error = 'Network error — check your connection.';
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
        more(type) {
            this.close();
            $wire.startAdding(type, this.after);
        },
    }"
    x-on:dragover="if (isFileDrag($event)) { $event.preventDefault(); dragging = true; if (collapsible) open = true; }"
    x-on:dragleave="if (!$el.contains($event.relatedTarget)) { dragging = false; if (collapsible && !text && !busy) open = false; }"
    x-on:drop="if (isFileDrag($event)) { $event.preventDefault(); dropped($event); }"
    x-on:keydown.escape="close()"
    @if($variant === 'between') x-on:click.outside="if (!busy) close()" @endif
>
    @if($variant === 'between')
        <div class="bi-between-line" x-show="!open"></div>
        <button type="button" class="bi-plus" x-show="!open" x-on:click="show()" title="Add a block here" aria-label="Add a block here">+</button>
    @endif

    <div class="bi-panel" x-show="open" @if($variant === 'between') x-cloak style="position: relative; z-index: 5; margin: 8px 0;" @endif>
        <div class="bi-row">
            <textarea x-ref="input" rows="1" class="bi-input"
                      x-model="text"
                      x-on:input="grow()"
                      x-on:paste="pasted($event)"
                      x-on:keydown.enter="if (!$event.shiftKey) { $event.preventDefault(); submit(); }"
                      :disabled="busy"
                      placeholder="Paste a link, type a note, or drop a file here"></textarea>
            <button type="button" class="bi-btn bi-btn--primary" x-on:click="submit()" :disabled="busy || !text.trim()">Add</button>
            <button type="button" class="bi-btn" x-on:click="$refs.file.click()" :disabled="busy" title="Upload a PDF, video, image or any file">
                <svg width="14" height="14" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M10 14V4M6 8l4-4 4 4M4 15v1a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1v-1"/>
                </svg>
                File
            </button>
            <input type="file" x-ref="file" style="display: none;" x-on:change="upload($event.target.files[0]); $event.target.value = ''">
        </div>

        <div x-show="busy && uploadName" x-cloak class="bi-progress">
            <span style="max-width: 40%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" x-text="uploadName"></span>
            <span class="bi-track"><span class="bi-bar" :style="'display:block;width: ' + progress + '%'"></span></span>
            <span x-text="progress + '%'"></span>
        </div>
        <div x-show="error" x-cloak class="bi-error" x-text="error"></div>

        <div class="bi-hint" style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
            <span>YouTube and video links play as video, PDFs and images show inline. Or add:</span>
            <span class="bi-types">
                @foreach(['quiz' => 'Quiz', 'live' => 'Live class', 'session' => 'Mentor session', 'note' => 'Long note', 'link' => 'Link with description'] as $typeKey => $typeLabel)
                    <button type="button" class="bi-type" x-on:click="more('{{ $typeKey }}')">{{ $typeLabel }}</button>
                @endforeach
            </span>
        </div>
    </div>
</div>
