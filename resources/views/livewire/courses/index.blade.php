<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\WithPagination;
use App\Models\Content;
use App\Models\Course;
use App\Models\File;
use App\Models\Module;
use App\Models\ModuleContent;
use App\Models\PdfNotesContent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

new #[Layout('layouts.app')] class extends Component {
    use WithPagination;

    public string $search = '';
    public bool $showCreateForm = false;

    #[Validate('required|string|max:255')]
    public string $title = '';

    #[Validate('required|string|max:255|unique:courses,slug')]
    public string $slug = '';

    /** Optional PDF whose bookmarks seed the course's modules and content. */
    public $pdfImportFileId = '';
    public ?string $pdfImportFileUrl = null;
    /** The module/content tree the browser parsed from the PDF outline, trimmed to what the creator kept. */
    public array $importStructure = [];

    public ?int $editingCourseId = null;
    public string $editTitle = '';
    public string $editSlug = '';

    public function updatedTitle($value)
    {
        $this->slug = \Illuminate\Support\Str::slug($value);
    }

    /** The creator's PDFs, in the shape the file picker renders. */
    #[Computed]
    public function pdfImportFiles(): array
    {
        return File::ownedBy(auth()->user())
            ->where('file_type', 'pdf')
            ->latest()
            ->get()
            ->map(fn (File $file) => $file->pickerEntry())
            ->all();
    }

    /**
     * When the picked PDF changes, hand its URL to the browser so pdf.js can read the
     * bookmarks. Mirrors the 'pdf-preview-changed' dispatch in create-content-form.
     */
    public function updatedPdfImportFileId($id)
    {
        $file = $id ? File::ownedBy(auth()->user())->find($id) : null;

        $this->pdfImportFileUrl = $file ? asset('storage/' . $file->file_path) : null;
        $this->importStructure = [];

        $this->dispatch('pdf-import-file-changed', url: $this->pdfImportFileUrl);
    }

    public function updatedSearch()
    {
        $this->resetPage();
    }

    #[Computed]
    public function courses()
    {
        return Course::managedBy(auth()->user())
            ->withCount('modules')
            ->when($this->search, fn($q) => $q->where('title', 'like', '%' . $this->search . '%'))
            ->orderBy('title')
            ->paginate(12);
    }

    public function createCourse()
    {
        $this->validate();

        Course::create([
            'title' => $this->title,
            'slug'  => $this->slug,
            'created_by' => auth()->id(),
        ]);

        $this->reset(['title', 'slug', 'showCreateForm', 'pdfImportFileId', 'pdfImportFileUrl', 'importStructure']);
        session()->flash('success', 'Course created successfully.');
    }

    /**
     * Create the course and its whole module tree from the outline the browser parsed
     * out of the chosen PDF. Every content is a page-range slice of that one PDF.
     */
    public function createCourseFromPdf()
    {
        $this->validate();

        $this->validate([
            'pdfImportFileId' => ['required', Rule::exists('files', 'id')->where('user_id', auth()->id())],
            'importStructure' => 'required|array|min:1|max:200',
            'importStructure.*.title' => 'required|string|max:255',
            'importStructure.*.contents' => 'required|array|min:1|max:150',
            'importStructure.*.contents.*.title' => 'required|string|max:255',
            'importStructure.*.contents.*.startPage' => 'required|integer|min:1',
            'importStructure.*.contents.*.endPage' => 'required|integer|min:1',
        ], [
            'pdfImportFileId.required' => 'Choose a PDF to import from.',
            'importStructure.required' => 'Nothing to import — the PDF has no usable bookmarks.',
        ]);

        $file = File::ownedBy(auth()->user())->findOrFail($this->pdfImportFileId);

        $course = DB::transaction(fn () => $this->buildCourseFromOutline($this->importStructure, $file));

        $this->reset(['title', 'slug', 'showCreateForm', 'pdfImportFileId', 'pdfImportFileUrl', 'importStructure']);

        return redirect()->route('course.show', $course->id);
    }

    private function buildCourseFromOutline(array $structure, File $file): Course
    {
        $course = Course::create([
            'title' => $this->title,
            'slug' => $this->slug,
            'created_by' => auth()->id(),
        ]);

        $fileUrl = asset('storage/' . $file->file_path);

        foreach (array_values($structure) as $mi => $moduleData) {
            $module = Module::create([
                'course_id' => $course->id,
                'title' => $moduleData['title'],
                'slug' => Str::slug($moduleData['title'] . '-' . Str::random(6)),
                'sort_order' => $mi + 1,
            ]);

            foreach (array_values($moduleData['contents']) as $ci => $contentData) {
                $start = max(1, (int) $contentData['startPage']);
                $end = max($start, (int) $contentData['endPage']);

                // PdfNotesContent guards its attributes, so set them one by one (as create-content-form does).
                $pdf = new PdfNotesContent();
                $pdf->name = $contentData['title'];
                $pdf->file_url = $fileUrl;
                $pdf->start_position = (string) $start;
                $pdf->end_position = (string) $end;
                $pdf->start_percentage = 0;
                $pdf->end_percentage = 100;
                $pdf->save();

                $content = Content::create([
                    'contentable_id' => $pdf->id,
                    'contentable_type' => PdfNotesContent::class,
                ]);

                $moduleContent = ModuleContent::create([
                    'module_id' => $module->id,
                    'label' => $contentData['title'],
                    'slug' => Str::slug($contentData['title'] . '-' . Str::random(6)),
                    'sort_order' => $ci + 1,
                ]);

                $moduleContent->contents()->attach($content->id, ['sort_order' => 1, 'is_exercise' => false]);
            }
        }

        return $course;
    }

    public function editCourse($courseId)
    {
        $course = Course::managedBy(auth()->user())->findOrFail($courseId);

        $this->resetValidation();
        $this->editingCourseId = $course->id;
        $this->editTitle = $course->title;
        $this->editSlug = $course->slug;
    }

    public function updateCourse()
    {
        $course = Course::managedBy(auth()->user())->findOrFail($this->editingCourseId);

        $this->validate([
            'editTitle' => 'required|string|max:255',
            'editSlug'  => 'required|string|max:255|unique:courses,slug,' . $course->id,
        ]);

        $course->update([
            'title' => $this->editTitle,
            'slug'  => $this->editSlug,
        ]);

        $this->reset(['editingCourseId', 'editTitle', 'editSlug']);
        session()->flash('success', 'Course updated successfully.');
    }

    public function closeCreateForm()
    {
        $this->reset(['title', 'slug', 'showCreateForm', 'pdfImportFileId', 'pdfImportFileUrl', 'importStructure']);
        $this->resetValidation();
    }

    public function deleteCourse($courseId)
    {
        Course::managedBy(auth()->user())->findOrFail($courseId)->delete();
        session()->flash('success', 'Course deleted.');
    }
}; ?>

<div style="display: flex; flex-direction: column; width: 100%; height: 100%; overflow-y: auto; background-color: #F9FAFB;">

    {{-- pdf.js, for reading bookmarks out of an imported PDF (shared engine, @once-guarded). --}}
    <x-pdf-viewer-engine />

    @once
    <script>
        window.waitForPdfjs = function () {
            return new Promise((resolve, reject) => {
                if (window.pdfjsLib) return resolve();
                const start = Date.now();
                const timer = setInterval(() => {
                    if (window.pdfjsLib) { clearInterval(timer); resolve(); }
                    else if (Date.now() - start > 8000) { clearInterval(timer); reject(new Error('pdf.js failed to load')); }
                }, 100);
            });
        };

        /**
         * Turn a pdf.js outline (bookmark tree) into an editable module/content tree:
         * top-level bookmarks are modules, their children are content slices, and
         * anything deeper is folded into the content label.
         */
        window.pdfOutlineToCourseTree = async function (pdf, outline, numPages) {
            async function pageOf(item, fallback) {
                try {
                    let dest = item.dest;
                    if (typeof dest === 'string') dest = await pdf.getDestination(dest);
                    if (!Array.isArray(dest) || !dest[0]) return fallback;
                    return (await pdf.getPageIndex(dest[0])) + 1;
                } catch (e) {
                    return fallback;
                }
            }

            function deepTitles(items) {
                let out = [];
                for (const it of items || []) {
                    out.push(it.title);
                    out = out.concat(deepTitles(it.items));
                }
                return out;
            }

            const clean = (s) => (s || '').replace(/\s+/g, ' ').trim();

            const mods = [];
            let prev = 1;
            for (const node of outline) {
                const startPage = await pageOf(node, prev);
                prev = startPage;
                mods.push({ node, startPage });
            }

            const tree = [];
            for (let i = 0; i < mods.length; i++) {
                const { node, startPage } = mods[i];
                const moduleEnd = i + 1 < mods.length
                    ? Math.max(startPage, mods[i + 1].startPage - 1)
                    : numPages;

                const children = node.items || [];
                const contents = [];

                if (!children.length) {
                    contents.push({ title: clean(node.title) || 'Section', include: true, startPage, endPage: moduleEnd });
                } else {
                    const kids = [];
                    let kprev = startPage;
                    for (const child of children) {
                        const cs = await pageOf(child, kprev);
                        kprev = cs;
                        kids.push({ child, cs });
                    }
                    for (let j = 0; j < kids.length; j++) {
                        const cs = j === 0 ? startPage : kids[j].cs;
                        const ce = j + 1 < kids.length ? Math.max(cs, kids[j + 1].cs - 1) : moduleEnd;
                        let title = clean(kids[j].child.title) || 'Section';
                        const deeper = deepTitles(kids[j].child.items).map(clean).filter(Boolean);
                        if (deeper.length) title = (title + ' — ' + deeper.join(' · ')).slice(0, 240);
                        contents.push({ title, include: true, startPage: cs, endPage: Math.max(cs, ce) });
                    }
                }

                tree.push({ title: clean(node.title) || 'Module', include: true, startPage, endPage: moduleEnd, contents });
            }
            return tree;
        };
    </script>
    @endonce

    <!-- Header -->
    <div style="background: white; border-bottom: 1px solid #E5E7EB; padding: 28px 40px; display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h1 style="margin: 0 0 4px; font-size: 26px; font-weight: 800; color: #111827;">Courses</h1>
            <p style="margin: 0; font-size: 14px; color: #6B7280;">Manage all available courses on the platform.</p>
        </div>
        <button wire:click="$set('showCreateForm', true)"
                style="background-color: #2563EB; color: white; border: none; border-radius: 9px; padding: 11px 22px; font-size: 14px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 7px; transition: background-color 0.2s;"
                onmouseover="this.style.backgroundColor='#1D4ED8'" onmouseout="this.style.backgroundColor='#2563EB'">
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
            </svg>
            New Course
        </button>
    </div>

    <!-- Create form modal overlay -->
    @if($showCreateForm)
    <div style="position: fixed; inset: 0; background: rgba(0,0,0,0.45); z-index: 50; display: flex; align-items: center; justify-content: center; padding: 20px;" wire:click.self="closeCreateForm">
        <div style="background: white; border-radius: 16px; padding: 36px; width: 100%; max-width: 560px; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,0.2);" wire:click.stop>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
                <h2 style="margin: 0; font-size: 20px; font-weight: 700; color: #111827;">Create New Course</h2>
                <button wire:click="closeCreateForm" style="background: none; border: none; cursor: pointer; color: #6B7280; padding: 4px;">
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <form
                x-data="{
                    status: 'idle',
                    numPages: 0,
                    tree: [],
                    error: '',
                    get hasTree() { return this.status === 'ready' && this.tree.length > 0; },
                    init() {
                        this.$wire.on('pdf-import-file-changed', (payload) => {
                            const url = payload?.url ?? payload?.[0]?.url ?? null;
                            url ? this.parse(url) : this.resetImport();
                        });
                    },
                    resetImport() { this.status = 'idle'; this.tree = []; this.numPages = 0; this.error = ''; },
                    async parse(url) {
                        this.status = 'parsing'; this.tree = []; this.error = '';
                        try {
                            await window.waitForPdfjs();
                            const pdf = await pdfjsLib.getDocument(url).promise;
                            this.numPages = pdf.numPages;
                            const outline = await pdf.getOutline();
                            if (!outline || !outline.length) { this.status = 'none'; return; }
                            this.tree = await window.pdfOutlineToCourseTree(pdf, outline, this.numPages);
                            this.status = this.tree.length ? 'ready' : 'none';
                        } catch (e) {
                            console.error(e);
                            this.error = 'Could not read this PDF.';
                            this.status = 'error';
                        }
                    },
                    buildPayload() {
                        return this.tree
                            .filter(m => m.include)
                            .map(m => ({
                                title: (m.title || 'Untitled module').trim().slice(0, 255),
                                contents: m.contents.filter(c => c.include).map(c => ({
                                    title: (c.title || 'Untitled').trim().slice(0, 255),
                                    startPage: c.startPage,
                                    endPage: c.endPage,
                                })),
                            }))
                            .filter(m => m.contents.length > 0);
                    },
                    async submit() {
                        this.error = '';
                        if (this.hasTree) {
                            const data = this.buildPayload();
                            if (!data.length) { this.error = 'Keep at least one module and section to import.'; return; }
                            await this.$wire.set('importStructure', data);
                            this.$wire.createCourseFromPdf();
                        } else {
                            this.$wire.createCourse();
                        }
                    },
                }"
                x-on:submit.prevent="submit()"
                style="display: flex; flex-direction: column; gap: 18px;"
            >
                <div>
                    <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;">Course Title</label>
                    <input wire:model.live="title" type="text" placeholder="e.g. Introduction to Web Development"
                           style="width: 100%; padding: 11px 14px; border: 1px solid #D1D5DB; border-radius: 8px; font-size: 14px; outline: none; transition: border-color 0.2s; box-sizing: border-box;"
                           onfocus="this.style.borderColor='#2563EB'" onblur="this.style.borderColor='#D1D5DB'">
                    @error('title') <span style="color: #EF4444; font-size: 12px; display: block; margin-top: 4px;">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;">Slug</label>
                    <input wire:model="slug" type="text" placeholder="auto-generated-from-title"
                           style="width: 100%; padding: 11px 14px; border: 1px solid #D1D5DB; border-radius: 8px; font-size: 14px; outline: none; color: #6B7280; box-sizing: border-box;"
                           onfocus="this.style.borderColor='#2563EB'" onblur="this.style.borderColor='#D1D5DB'">
                    @error('slug') <span style="color: #EF4444; font-size: 12px; display: block; margin-top: 4px;">{{ $message }}</span> @enderror
                </div>

                <div style="border-top: 1px solid #F3F4F6; padding-top: 16px;">
                    <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 2px;">
                        Import structure from a PDF <span style="font-weight: 400; color: #9CA3AF;">(optional)</span>
                    </label>
                    <p style="margin: 0 0 10px; font-size: 12px; color: #6B7280;">
                        Pick a PDF with bookmarks — its outline becomes your modules, and each bookmark a page-range lesson.
                    </p>

                    <x-file-picker model="pdfImportFileId" kind="pdf" :files="$this->pdfImportFiles" :live="true" />
                    @error('pdfImportFileId') <span style="color: #EF4444; font-size: 12px; display: block; margin-top: 4px;">{{ $message }}</span> @enderror
                    @error('importStructure') <span style="color: #EF4444; font-size: 12px; display: block; margin-top: 4px;">{{ $message }}</span> @enderror

                    <template x-if="status === 'parsing'">
                        <p style="margin: 10px 0 0; font-size: 13px; color: #6B7280;">Reading bookmarks…</p>
                    </template>
                    <template x-if="status === 'none'">
                        <p style="margin: 10px 0 0; font-size: 13px; color: #6B7280;">
                            This PDF has no bookmarks — create the course and add modules yourself.
                        </p>
                    </template>
                    <template x-if="status === 'error'">
                        <p style="margin: 10px 0 0; font-size: 13px; color: #EF4444;" x-text="error"></p>
                    </template>

                    <div x-show="hasTree" x-cloak style="margin-top: 12px; max-height: 320px; overflow-y: auto; border: 1px solid #E5E7EB; border-radius: 8px; padding: 10px 12px;">
                        <p style="margin: 0 0 8px; font-size: 12px; color: #6B7280;">
                            <span x-text="tree.filter(m => m.include).length"></span> module(s),
                            <span x-text="tree.filter(m => m.include).reduce((n, m) => n + m.contents.filter(c => c.include).length, 0)"></span> lesson(s) will be created. Untick anything you don't want.
                        </p>
                        <template x-for="(m, mi) in tree" :key="mi">
                            <div style="margin-bottom: 6px;">
                                <label style="display: flex; gap: 8px; align-items: center;">
                                    <input type="checkbox" x-model="m.include">
                                    <input type="text" x-model="m.title"
                                           style="flex: 1; min-width: 0; padding: 5px 8px; border: 1px solid #E5E7EB; border-radius: 6px; font-size: 13px; font-weight: 600; color: #111827;">
                                </label>
                                <div x-show="m.include" style="margin: 3px 0 6px 24px;">
                                    <template x-for="(c, ci) in m.contents" :key="ci">
                                        <label style="display: flex; gap: 8px; align-items: center; margin-top: 3px;">
                                            <input type="checkbox" x-model="c.include">
                                            <input type="text" x-model="c.title"
                                                   style="flex: 1; min-width: 0; padding: 4px 8px; border: 1px solid #E5E7EB; border-radius: 6px; font-size: 12px; color: #374151;">
                                            <span x-text="'p.' + c.startPage + '–' + c.endPage"
                                                  style="font-size: 11px; color: #9CA3AF; white-space: nowrap;"></span>
                                        </label>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </div>
                    <p x-show="hasTree && error" x-cloak style="margin: 6px 0 0; font-size: 12px; color: #EF4444;" x-text="error"></p>
                </div>

                <div style="display: flex; gap: 12px; margin-top: 6px;">
                    <button type="button" wire:click="closeCreateForm"
                            style="flex: 1; padding: 11px; border: 1px solid #D1D5DB; border-radius: 8px; background: white; font-size: 14px; font-weight: 600; color: #374151; cursor: pointer;">
                        Cancel
                    </button>
                    <button type="submit" wire:loading.attr="disabled" wire:target="createCourse,createCourseFromPdf"
                            style="flex: 1; padding: 11px; border: none; border-radius: 8px; background: #2563EB; font-size: 14px; font-weight: 600; color: white; cursor: pointer;">
                        <span x-text="hasTree ? 'Create course from PDF' : 'Create Course'">Create Course</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
    @endif

    <!-- Edit form modal overlay -->
    @if($editingCourseId)
    <div style="position: fixed; inset: 0; background: rgba(0,0,0,0.45); z-index: 50; display: flex; align-items: center; justify-content: center;" wire:click.self="$set('editingCourseId', null)">
        <div style="background: white; border-radius: 16px; padding: 36px; width: 100%; max-width: 480px; box-shadow: 0 20px 60px rgba(0,0,0,0.2);" wire:click.stop>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
                <h2 style="margin: 0; font-size: 20px; font-weight: 700; color: #111827;">Edit Course</h2>
                <button wire:click="$set('editingCourseId', null)" style="background: none; border: none; cursor: pointer; color: #6B7280; padding: 4px;">
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <form wire:submit="updateCourse" style="display: flex; flex-direction: column; gap: 18px;">
                <div>
                    <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;">Course Title</label>
                    <input wire:model="editTitle" type="text" placeholder="e.g. Introduction to Web Development"
                           style="width: 100%; padding: 11px 14px; border: 1px solid #D1D5DB; border-radius: 8px; font-size: 14px; outline: none; transition: border-color 0.2s; box-sizing: border-box;"
                           onfocus="this.style.borderColor='#2563EB'" onblur="this.style.borderColor='#D1D5DB'">
                    @error('editTitle') <span style="color: #EF4444; font-size: 12px; display: block; margin-top: 4px;">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;">Slug</label>
                    <input wire:model="editSlug" type="text"
                           style="width: 100%; padding: 11px 14px; border: 1px solid #D1D5DB; border-radius: 8px; font-size: 14px; outline: none; color: #6B7280; box-sizing: border-box;"
                           onfocus="this.style.borderColor='#2563EB'" onblur="this.style.borderColor='#D1D5DB'">
                    @error('editSlug') <span style="color: #EF4444; font-size: 12px; display: block; margin-top: 4px;">{{ $message }}</span> @enderror
                </div>

                <div style="display: flex; gap: 12px; margin-top: 6px;">
                    <button type="button" wire:click="$set('editingCourseId', null)"
                            style="flex: 1; padding: 11px; border: 1px solid #D1D5DB; border-radius: 8px; background: white; font-size: 14px; font-weight: 600; color: #374151; cursor: pointer;">
                        Cancel
                    </button>
                    <button type="submit"
                            style="flex: 1; padding: 11px; border: none; border-radius: 8px; background: #2563EB; font-size: 14px; font-weight: 600; color: white; cursor: pointer;">
                        Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
    @endif

    <div style="padding: 30px 40px;">

        @if (session('success'))
            <div style="background-color: #ECFDF5; color: #065F46; padding: 12px 16px; border-radius: 8px; font-size: 13px; font-weight: 500; margin-bottom: 20px; border: 1px solid #A7F3D0;">
                {{ session('success') }}
            </div>
        @endif

        <!-- Search -->
        <div style="position: relative; max-width: 380px; margin-bottom: 24px;">
            <svg width="16" height="16" fill="none" stroke="#9CA3AF" viewBox="0 0 24 24" style="position: absolute; left: 13px; top: 50%; transform: translateY(-50%);">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0"></path>
            </svg>
            <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search courses..."
                   style="width: 100%; padding: 10px 14px 10px 38px; border: 1px solid #D1D5DB; border-radius: 8px; font-size: 14px; background: white; outline: none; box-sizing: border-box;">
        </div>

        <!-- Grid -->
        @if($this->courses->isNotEmpty())
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px;">
                @foreach($this->courses as $course)
                    <div style="background: white; border: 1px solid #E5E7EB; border-radius: 14px; padding: 22px; display: flex; flex-direction: column; gap: 14px; transition: box-shadow 0.2s, border-color 0.2s;"
                         onmouseover="this.style.boxShadow='0 4px 12px rgba(0,0,0,0.08)'; this.style.borderColor='#BFDBFE'"
                         onmouseout="this.style.boxShadow='none'; this.style.borderColor='#E5E7EB'">

                        <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                            <div style="width: 44px; height: 44px; border-radius: 11px; background: linear-gradient(135deg, #EEF2FF, #E0E7FF); display: flex; align-items: center; justify-content: center; color: #4338CA;">
                                <svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.232.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
                                </svg>
                            </div>
                            <div style="display: flex; align-items: center; gap: 4px;">
                                <button wire:click="editCourse({{ $course->id }})" title="Edit course"
                                        style="background: none; border: none; cursor: pointer; color: #9CA3AF; padding: 4px; transition: color 0.2s;"
                                        onmouseover="this.style.color='#2563EB'" onmouseout="this.style.color='#9CA3AF'">
                                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                    </svg>
                                </button>
                                <button wire:click="deleteCourse({{ $course->id }})" wire:confirm="Delete this course? This cannot be undone."
                                        style="background: none; border: none; cursor: pointer; color: #9CA3AF; padding: 4px; transition: color 0.2s;"
                                        onmouseover="this.style.color='#EF4444'" onmouseout="this.style.color='#9CA3AF'">
                                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <div>
                            <h3 style="margin: 0 0 5px; font-size: 15px; font-weight: 700; color: #111827;">{{ $course->title }}</h3>
                            <p style="margin: 0; font-size: 12px; color: #6B7280;">Slug: {{ $course->slug }}</p>
                        </div>

                        <div style="display: flex; justify-content: space-between; align-items: center; padding-top: 12px; border-top: 1px solid #F3F4F6;">
                            <span style="font-size: 12px; color: #6B7280; display: flex; align-items: center; gap: 5px;">
                                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                                </svg>
                                {{ $course->modules_count }} module(s)
                            </span>
                            <a href="{{ route('course.show', $course->id) }}"
                               style="font-size: 12px; font-weight: 600; color: #2563EB; text-decoration: none; display: flex; align-items: center; gap: 4px;">
                                View
                                <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                </svg>
                            </a>
                        </div>
                    </div>
                @endforeach
            </div>

            <!-- Pagination -->
            <div style="margin-top: 30px;">
                {{ $this->courses->links() }}
            </div>
        @else
            <div style="text-align: center; padding: 80px 20px; background: white; border: 1px dashed #D1D5DB; border-radius: 14px;">
                <svg width="48" height="48" fill="none" stroke="#9CA3AF" viewBox="0 0 24 24" style="margin: 0 auto 14px; display: block;">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.232.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
                </svg>
                <h3 style="font-size: 16px; font-weight: 600; color: #374151; margin: 0 0 8px;">No courses found</h3>
                <p style="font-size: 14px; color: #6B7280; margin: 0 0 20px;">
                    @if($search) No courses match "{{ $search }}". @else Get started by creating your first course. @endif
                </p>
                @if(!$search)
                    <button wire:click="$set('showCreateForm', true)"
                            style="background-color: #2563EB; color: white; border: none; border-radius: 8px; padding: 10px 20px; font-size: 14px; font-weight: 600; cursor: pointer;">
                        Create First Course
                    </button>
                @endif
            </div>
        @endif

    </div>
</div>
