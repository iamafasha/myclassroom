<?php

use App\Models\Content;
use App\Models\ModuleContent;
use App\Models\NoteContent;
use App\Models\PdfNotesContent;
use App\Models\VideoContent;
use App\Models\ImageContent;
use App\Models\LinkContent;
use App\Models\QuizContent;
use App\Models\File;
use Livewire\Attributes\Url;

use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] class extends Component
{
    use WithFileUploads;
    public $moduleContentId;
    public $moduleId;
    #[Url]
    public $type = 'note';

    public $contentId = null;
    public $isEditing = false;

    public $label = '';
    public $noteText = '';
    public $courseId;

    public $pdfFileId = '';
    public $pdfSourceType = 'existing'; // 'existing' or 'upload'
    public $pdfUpload = null;
    public $pdfStartPage = '';
    public $pdfEndPage = '';
    public $pdfStartPercentage = 0;
    public $pdfEndPercentage = 100;
    public $pdfFiles = [];

    public $videoFileId = '';
    public $videoSourceType = 'file'; // 'file' or 'url'
    public $videoExternalUrl = '';
    public $videoStartTime = '';
    public $videoEndTime = '';
    public $videoFiles = [];

    public $imageFileId = '';
    public $imageSourceType = 'file';
    public $imageExternalUrl;
    public $imageFiles = [];

    public $linkUrl = '';
    public $linkDescription = '';
    public $isExercise = false;

    public $quizDescription = '';
    public $questions = [];
    
    public function mount($moduleContentId, $contentId = null)
    {
        $moduleContent = ModuleContent::findOrFail($moduleContentId);
        $this->moduleContentId = $moduleContentId;
        $this->moduleId = $moduleContent->module_id;
        $this->courseId = $moduleContent->module->course_id;
        $this->label = $moduleContent->label ?? '';
        $this->pdfFiles = File::where('file_type', 'pdf')->get();
        $this->videoFiles = File::whereIn('file_type', ['video', 'mp4', 'mov', 'avi'])->get();
        $this->imageFiles = File::whereIn('file_type', ['image', 'png', 'jpg', 'jpeg'])->get();

        if ($contentId) {
            $content = Content::findOrFail($contentId);
            abort_if(!$content->contentable, 404);

            $this->contentId = $contentId;
            $this->isEditing = true;
            $this->loadContentable($content->contentable);

            $pivot = $moduleContent->contents()->where('content_id', $contentId)->first()?->pivot;
            $this->isExercise = (bool) $pivot?->is_exercise;
        }

        if (empty($this->questions)) {
            $this->addQuestion();
        }
    }

    private function loadContentable($contentable)
    {
        $storagePrefix = asset('storage') . '/';

        $resolveFile = function ($fileUrl) use ($storagePrefix) {
            if (!str_starts_with($fileUrl, $storagePrefix)) {
                return null;
            }
            return File::where('file_path', substr($fileUrl, strlen($storagePrefix)))->first();
        };

        if ($contentable instanceof NoteContent) {
            $this->type = 'note';
            $this->noteText = $contentable->content;
        } elseif ($contentable instanceof PdfNotesContent) {
            $this->type = 'pdf';
            $this->pdfSourceType = 'existing';
            $this->pdfStartPage = $contentable->start_position;
            $this->pdfEndPage = $contentable->end_position;
            $this->pdfStartPercentage = $contentable->start_percentage ?? 0;
            $this->pdfEndPercentage = $contentable->end_percentage ?? 100;
            $file = $resolveFile($contentable->file_url);
            $this->pdfFileId = $file?->id ?? '';
        } elseif ($contentable instanceof VideoContent) {
            $this->type = 'video';
            $this->videoStartTime = $contentable->start_time;
            $this->videoEndTime = $contentable->end_time;
            $file = $resolveFile($contentable->file_url);
            if ($file) {
                $this->videoSourceType = 'file';
                $this->videoFileId = $file->id;
            } else {
                $this->videoSourceType = 'url';
                $this->videoExternalUrl = $contentable->file_url;
            }
        } elseif ($contentable instanceof ImageContent) {
            $this->type = 'image';
            $file = $resolveFile($contentable->file_url);
            if ($file) {
                $this->imageSourceType = 'file';
                $this->imageFileId = $file->id;
            } else {
                $this->imageSourceType = 'url';
                $this->imageExternalUrl = $contentable->file_url;
            }
        } elseif ($contentable instanceof LinkContent) {
            $this->type = 'link';
            $this->linkUrl = $contentable->url;
            $this->linkDescription = $contentable->description;
        } elseif ($contentable instanceof QuizContent) {
            $this->type = 'quiz';
            $this->quizDescription = $contentable->description;
            $this->questions = $contentable->questions ?: [];
        }
    }

    public function pdfPreviewUrl()
    {
        if ($this->pdfSourceType === 'upload' && $this->pdfUpload) {
            return $this->pdfUpload->temporaryUrl();
        }

        if ($this->pdfSourceType === 'existing' && $this->pdfFileId) {
            $file = File::find($this->pdfFileId);
            return $file ? asset('storage/' . $file->file_path) : null;
        }

        return null;
    }

    public function updated($name)
    {
        $pdfPreviewFields = ['pdfStartPage', 'pdfEndPage', 'pdfStartPercentage', 'pdfEndPercentage', 'pdfFileId', 'pdfSourceType', 'pdfUpload'];

        if ($name === 'type' || ($this->type === 'pdf' && in_array($name, $pdfPreviewFields))) {
            $this->dispatch('pdf-preview-changed',
                url: $this->type === 'pdf' ? $this->pdfPreviewUrl() : null,
                startPage: $this->pdfStartPage,
                endPage: $this->pdfEndPage,
                startPercent: (int) $this->pdfStartPercentage,
                endPercent: (int) $this->pdfEndPercentage,
            );
        }
    }

    public function addQuestion()
    {
        $this->questions[] = [
            'question' => '',
            'options' => ['', ''],
            'correct_answers' => [0],
        ];
    }

    public function removeQuestion($index)
    {
        if (count($this->questions) > 1) {
            unset($this->questions[$index]);
            $this->questions = array_values($this->questions);
        }
    }

    public function addOption($qIndex)
    {
        $this->questions[$qIndex]['options'][] = '';
    }

    public function removeOption($qIndex, $oIndex)
    {
        if (count($this->questions[$qIndex]['options']) > 2) {
            unset($this->questions[$qIndex]['options'][$oIndex]);
            $this->questions[$qIndex]['options'] = array_values($this->questions[$qIndex]['options']);
            
            $correct = $this->questions[$qIndex]['correct_answers'] ?? [];
            $newCorrect = [];
            foreach ($correct as $c) {
                if ($c < $oIndex) {
                    $newCorrect[] = $c;
                } elseif ($c > $oIndex) {
                    $newCorrect[] = $c - 1;
                }
            }
            $this->questions[$qIndex]['correct_answers'] = array_values($newCorrect);
        }
    }

    public function toggleCorrectAnswer($qIndex, $oIndex)
    {
        $correct = $this->questions[$qIndex]['correct_answers'] ?? [];
        if (in_array($oIndex, $correct)) {
            $correct = array_values(array_diff($correct, [$oIndex]));
        } else {
            $correct[] = $oIndex;
        }
        $this->questions[$qIndex]['correct_answers'] = array_values($correct);
    }

    public function save()
    {
        $this->validate([
            'label' => 'required|string|max:255',
        ]);

        $existingContentable = null;
        if ($this->isEditing) {
            $existingContentable = Content::findOrFail($this->contentId)->contentable;
        }

        if ($this->type === 'note') {

            $this->validate([
                'noteText' => 'required|string',
            ]);

            $contentable = $existingContentable ?: new NoteContent();
            $contentable->content = $this->noteText;
            $contentable->save();
        } elseif ($this->type === 'pdf') {
            if ($this->pdfSourceType === 'upload') {
                $this->validate([
                    'pdfUpload' => 'required|file|mimes:pdf|max:20480',
                    'pdfStartPage' => 'nullable|string',
                    'pdfEndPage' => 'nullable|string',
                ]);

                $file = File::create([
                    'name' => $this->pdfUpload->getClientOriginalName(),
                    'file_path' => $this->pdfUpload->store('uploads', 'public'),
                    'file_type' => 'pdf',
                ]);
            } else {
                $this->validate([
                    'pdfFileId' => 'required|exists:files,id',
                    'pdfStartPage' => 'nullable|string',
                    'pdfEndPage' => 'nullable|string',
                ]);

                $file = File::find($this->pdfFileId);
            }

            $this->validate([
                'pdfStartPercentage' => 'nullable|integer|min:0|max:100',
                'pdfEndPercentage' => 'nullable|integer|min:0|max:100',
            ]);

            if ($this->pdfStartPage !== '' && $this->pdfEndPage !== '' && (int) $this->pdfStartPage === (int) $this->pdfEndPage
                && (int) $this->pdfEndPercentage <= (int) $this->pdfStartPercentage) {
                $this->addError('pdfEndPercentage', 'End crop percentage must be greater than start crop percentage when start and end page are the same.');
                return;
            }

            $contentable = $existingContentable ?: new PdfNotesContent();
            $contentable->name = $this->label;
            $contentable->file_url = asset('storage/' . $file->file_path);
            $contentable->start_position = $this->pdfStartPage;
            $contentable->end_position = $this->pdfEndPage;
            $contentable->start_percentage = $this->pdfStartPercentage !== '' ? (int) $this->pdfStartPercentage : 0;
            $contentable->end_percentage = $this->pdfEndPercentage !== '' ? (int) $this->pdfEndPercentage : 100;
            $contentable->save();
        } elseif ($this->type === 'video') {
            
            if ($this->videoSourceType === 'file') {
                $this->validate([
                    'videoFileId' => 'required|exists:files,id',
                    'videoStartTime' => 'nullable|string',
                    'videoEndTime' => 'nullable|string',
                ]);

                $file = File::find($this->videoFileId);
                $fileUrl = asset('storage/' . $file->file_path);
                $startTime = $this->videoStartTime;
                $endTime = $this->videoEndTime;
            } else {
                $this->validate([
                    'videoExternalUrl' => 'required|url',
                    'videoStartTime' => 'nullable|string',
                    'videoEndTime' => 'nullable|string',
                ]);

                $url = $this->videoExternalUrl;
                $startTime = $this->videoStartTime;
                $endTime = $this->videoEndTime;

                $ytDlpBin = base_path('yt-dlp');
                $dir = storage_path('app/public/videos');
                if (!file_exists($dir)) {
                    mkdir($dir, 0755, true);
                }

                $filename = 'yt_' . time() . '_' . \Illuminate\Support\Str::random(6) . '.mp4';
                $outputPath = $dir . '/' . $filename;

                $sectionParam = '';
                if ($startTime || $endTime) {
                    $start = $startTime ?: '00:00';
                    $end = $endTime ?: 'inf';
                    $sectionParam = '--download-sections "*' . $start . '-' . $end . '"';
                }

                $cmd = sprintf(
                    '%s %s -f "best[ext=mp4]/best" --force-overwrites -o %s %s 2>&1',
                    escapeshellcmd($ytDlpBin),
                    $sectionParam,
                    escapeshellarg($outputPath),
                    escapeshellarg($url)
                );

                exec($cmd, $output, $returnCode);

                if ($returnCode === 0 && file_exists($outputPath)) {
                    $fileUrl = asset('storage/videos/' . $filename);
                    $startTime = null;
                    $endTime = null;
                } else {
                    $fileUrl = $url;
                }
            }

            $contentable = $existingContentable ?: new VideoContent();
            $contentable->name = $this->label;
            $contentable->file_url = $fileUrl;
            $contentable->start_time = $startTime;
            $contentable->end_time = $endTime;
            $contentable->save();
        } elseif ($this->type === 'link') {
            $this->validate([
                'linkUrl' => 'required|url',
                'linkDescription' => 'nullable|string',
            ]);

            $contentable = $existingContentable ?: new LinkContent();
            $contentable->name = $this->label;
            $contentable->url = $this->linkUrl;
            $contentable->description = $this->linkDescription;
            $contentable->save();
        } elseif ($this->type === 'image') {

                $this->validate([
                    'imageSourceType' => 'required|in:file,url',
                    'imageFileId' => 'required_if:imageSourceType,file|exists:files,id',
                    'imageExternalUrl' => 'required_if:imageSourceType,url',
                ]);

                $file = File::find($this->imageFileId);

                $contentable = $existingContentable ?: new ImageContent();
                $contentable->name = $this->label;
            
                if($this->imageSourceType == 'file'){
                    $contentable->file_url = asset('storage/' . $file->file_path);
                }else{
                    $contentable->file_url = $this->imageExternalUrl;
                }

                $contentable->save();
       } elseif ($this->type === 'quiz') {
            $this->validate([
                'questions' => 'required|array|min:1',
                'questions.*.question' => 'required|string',
                'questions.*.options.*' => 'required|string',
            ]);

            $contentable = $existingContentable ?: new QuizContent();
            $contentable->title = $this->label;
            $contentable->description = $this->quizDescription;
            $contentable->questions = $this->questions;
            $contentable->save();
        }

        if ($this->isEditing) {
            $moduleContent = ModuleContent::findOrFail($this->moduleContentId);
            $moduleContent->label = $this->label;
            if (!$moduleContent->slug) {
                $moduleContent->slug = \Illuminate\Support\Str::slug($this->label . '-' . time());
            }
            $moduleContent->save();

            $moduleContent->contents()->updateExistingPivot($this->contentId, ['is_exercise' => $this->isExercise]);
        } else {
            $content = new Content();
            $content->contentable_id = $contentable->id;
            $content->contentable_type = get_class($contentable);
            $content->save();

            $moduleContent = ModuleContent::findOrFail($this->moduleContentId);
            if (!$moduleContent->label) {
                $moduleContent->label = $this->label;
            }
            if (!$moduleContent->slug) {
                $moduleContent->slug = \Illuminate\Support\Str::slug($this->label . '-' . time());
            }
            $moduleContent->save();

            $maxOrder = \Illuminate\Support\Facades\DB::table('content_module_content')
                ->where('module_content_id', $moduleContent->id)
                ->max('sort_order') ?? 0;

            $moduleContent->contents()->attach($content->id, ['sort_order' => $maxOrder + 1, 'is_exercise' => $this->isExercise]);
        }

        return redirect()->route('content.show', $this->moduleContentId);
    }
    

};

?>

<div style="width: 100%; height: 100%; overflow-y: auto;">
@once
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
    <script>
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
    </script>
@endonce
<div class="max-w-8xl mx-auto mt-10 mb-10" style="display: flex; align-items: flex-start; gap: 24px; padding: 0 24px;">

<div class="flex-1 min-w-0 p-6 bg-white shadow-md rounded-lg border border-gray-200">

    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold text-gray-800">{{ $isEditing ? 'Edit Content' : 'Add Content to Module' }}</h1>

        <select wire:model.live="type" @disabled($isEditing) class="border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 p-2 border" style="outline: none;">
            <option value="note">Text Note</option>
            <option value="pdf">PDF Document</option>
            <option value="video">Video Content</option>
            <option value="image">Image Content</option>
            <option value="link">External Link</option>
            <option value="quiz">Interactive Quiz</option>
        </select>
    </div>
    
    <div class="mb-4">
        <label class="block text-sm font-medium text-gray-700 mb-1">Content Label</label>
        <input type="text" wire:model="label" class="w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 p-2 border" style="outline: none;" placeholder="e.g. Introduction Note">
        @error('label') <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> @enderror
    </div>

    @if(isset($type))

        @if($type === 'note')
            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 mb-1">Note Details</label>
                <textarea wire:model="noteText" rows="6" class="w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 p-2 border" style="outline: none;" placeholder="Write your content here..."></textarea>
                @error('noteText') <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> @enderror
            </div>
        @elseif($type === 'pdf')
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">PDF Source</label>
                <div class="flex gap-4 mb-3">
                    <label class="inline-flex items-center cursor-pointer">
                        <input type="radio" wire:model.live="pdfSourceType" value="existing" class="form-radio text-indigo-600">
                        <span class="ml-2 text-sm text-gray-700 font-medium">Choose Existing File</span>
                    </label>
                    <label class="inline-flex items-center cursor-pointer">
                        <input type="radio" wire:model.live="pdfSourceType" value="upload" class="form-radio text-indigo-600">
                        <span class="ml-2 text-sm text-gray-700 font-medium">Upload New PDF</span>
                    </label>
                </div>
            </div>

            @if($pdfSourceType === 'existing')
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Select PDF File</label>
                    <select wire:model.live="pdfFileId" class="w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 p-2 border" style="outline: none;">
                        <option value="">-- Choose a PDF --</option>
                        @foreach($pdfFiles as $file)
                            <option value="{{ $file->id }}">{{ $file->name }}</option>
                        @endforeach
                    </select>
                    @error('pdfFileId') <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                </div>
            @else
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Upload PDF</label>
                    <input type="file" wire:model="pdfUpload" accept="application/pdf" class="w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 p-2 border" style="outline: none;">
                    <div wire:loading wire:target="pdfUpload" class="text-xs text-indigo-600 mt-1">Uploading&hellip;</div>
                    @error('pdfUpload') <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                    @if($pdfUpload)
                        <p class="text-xs text-gray-500 mt-1">Selected: {{ $pdfUpload->getClientOriginalName() }}</p>
                    @endif
                </div>
            @endif

            <div class="flex gap-4 mb-4">
                <div class="w-1/2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Read From Page (Optional)</label>
                    <input type="text" wire:model.live.debounce.500ms="pdfStartPage" class="w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 p-2 border" style="outline: none;" placeholder="e.g. 5">
                    @error('pdfStartPage') <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                </div>
                <div class="w-1/2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Read To Page (Optional)</label>
                    <input type="text" wire:model.live.debounce.500ms="pdfEndPage" class="w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 p-2 border" style="outline: none;" placeholder="e.g. 10">
                    @error('pdfEndPage') <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                </div>
            </div>

            <div class="flex gap-4 mb-6">
                <div class="w-1/2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Start Page &mdash; Show From <span class="font-semibold text-indigo-600">{{ $pdfStartPercentage }}%</span> Down
                    </label>
                    <input type="range" min="0" max="100" step="5" wire:model.live.debounce.300ms="pdfStartPercentage" class="w-full">
                    <p class="text-xs text-gray-500 mt-1">Crops the top of the first page shown. 0% shows the whole page from the top.</p>
                    @error('pdfStartPercentage') <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                </div>
                <div class="w-1/2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        End Page &mdash; Show Up To <span class="font-semibold text-indigo-600">{{ $pdfEndPercentage }}%</span> Down
                    </label>
                    <input type="range" min="0" max="100" step="5" wire:model.live.debounce.300ms="pdfEndPercentage" class="w-full">
                    <p class="text-xs text-gray-500 mt-1">Crops the bottom of the last page shown. 100% shows the whole page to the bottom.</p>
                    @error('pdfEndPercentage') <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                </div>
            </div>

        @elseif($type === 'video')
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">Video Source</label>
                <div class="flex gap-4 mb-3">
                    <label class="inline-flex items-center cursor-pointer">
                        <input type="radio" wire:model.live="videoSourceType" value="file" class="form-radio text-indigo-600">
                        <span class="ml-2 text-sm text-gray-700 font-medium">Uploaded File</span>
                    </label>
                    <label class="inline-flex items-center cursor-pointer">
                        <input type="radio" wire:model.live="videoSourceType" value="url" class="form-radio text-indigo-600">
                        <span class="ml-2 text-sm text-gray-700 font-medium">External URL (YouTube / Direct Link)</span>
                    </label>
                </div>
            </div>

            @if($videoSourceType === 'file')
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Select Video File</label>
                    <select wire:model="videoFileId" class="w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 p-2 border" style="outline: none;">
                        <option value="">-- Choose a Video --</option>
                        @foreach($videoFiles as $file)
                            <option value="{{ $file->id }}">{{ $file->name }}</option>
                        @endforeach
                    </select>
                    @error('videoFileId') <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                    <p class="text-xs text-gray-500 mt-1">If your video is not here, <a href="{{ route('files.index') }}" class="text-indigo-600 hover:underline">upload it in the File Manager</a> first.</p>
                </div>

      
            @else
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Video URL (YouTube link, MP4 link, etc.)</label>
                    <input type="url" wire:model="videoExternalUrl" class="w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 p-2 border" style="outline: none;" placeholder="https://www.youtube.com/watch?v=...">
                    @error('videoExternalUrl') <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                </div>
            @endif
            
            <div class="flex gap-4 mb-6">
                <div class="w-1/2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Start Time (Optional, e.g. 01:20)</label>
                    <input type="text" wire:model="videoStartTime" class="w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 p-2 border" style="outline: none;" placeholder="00:00">
                    @error('videoStartTime') <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                </div>
                <div class="w-1/2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">End Time (Optional, e.g. 05:30)</label>
                    <input type="text" wire:model="videoEndTime" class="w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 p-2 border" style="outline: none;" placeholder="00:00">
                    @error('videoEndTime') <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                </div>
            </div>
        @elseif($type === 'link')
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Target Link URL</label>
                <input type="url" wire:model="linkUrl" class="w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 p-2 border" style="outline: none;" placeholder="https://example.com/resource">
                @error('linkUrl') <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> @enderror
            </div>

            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 mb-1">Description (Optional)</label>
                <textarea wire:model="linkDescription" rows="4" class="w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 p-2 border" style="outline: none;" placeholder="Brief description of what the student will find at this link..."></textarea>
                @error('linkDescription') <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> @enderror
            </div>
        @elseif($type === 'image')

             <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">Image Source</label>
                <div class="flex gap-4 mb-3">
                    <label class="inline-flex items-center cursor-pointer">
                        <input type="radio" wire:model.live="imageSourceType" value="file" class="form-radio text-indigo-600">
                        <span class="ml-2 text-sm text-gray-700 font-medium">Uploaded File</span>
                    </label>
                    <label class="inline-flex items-center cursor-pointer">
                        <input type="radio" wire:model.live="imageSourceType" value="url" class="form-radio text-indigo-600">
                        <span class="ml-2 text-sm text-gray-700 font-medium">External URL</span>
                    </label>
                </div>
            </div>

            @if($imageSourceType == 'file')
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Select Image File</label>
                <select wire:model="imageFileId" class="w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 p-2 border" style="outline: none;">
                    <option value="">-- Choose an Image --</option>
                    @foreach($imageFiles as $file)
                        <option value="{{ $file->id }}">{{ $file->name }}</option>
                    @endforeach
                </select>
                
                @error('imageFileId') <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                <p class="text-xs text-gray-500 mt-1">If your image is not here, <a href="{{ route('files.index') }}" class="text-indigo-600 hover:underline">upload it in the File Manager</a> first.</p>
            </div>
            @else
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Image URL</label>
                    <input type="url" wire:model="imageExternalUrl" class="w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 p-2 border" style="outline: none;" placeholder="https://....jpg">
                    @error('imageExternalUrl') <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                </div>
            @endif

        @elseif($type === 'quiz')
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Quiz Instructions / Description (Optional)</label>
                <textarea wire:model="quizDescription" rows="2" class="w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 p-2 border" style="outline: none;" placeholder="Brief instructions for students taking this quiz..."></textarea>
                @error('quizDescription') <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> @enderror
            </div>

            <div class="mb-6">
                <div class="flex justify-between items-center mb-3">
                    <h3 class="text-base font-semibold text-gray-800">Quiz Questions & Objectives</h3>
                    <button type="button" wire:click="addQuestion" class="bg-indigo-50 text-indigo-600 border border-indigo-200 px-3 py-1 rounded text-xs font-semibold hover:bg-indigo-100 transition-colors">
                        + Add Question
                    </button>
                </div>

                @foreach($questions as $qIndex => $q)
                    <div class="p-4 mb-4 border border-gray-200 bg-gray-50 rounded-lg relative">
                        <div class="flex justify-between items-center mb-2">
                            <span class="text-xs font-bold uppercase tracking-wider text-indigo-600">Question {{ $qIndex + 1 }}</span>
                            @if(count($questions) > 1)
                                <button type="button" wire:click="removeQuestion({{ $qIndex }})" class="text-red-500 text-xs hover:underline">
                                    Remove Question
                                </button>
                            @endif
                        </div>

                        <div class="mb-3">
                            <input type="text" wire:model="questions.{{ $qIndex }}.question" class="w-full border-gray-300 rounded-md p-2 border text-sm bg-white" style="outline: none;" placeholder="Enter question objective (e.g. Which of the following are primary colors?)">
                            @error('questions.'.$qIndex.'.question') <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                        </div>

                        <div class="ml-2">
                            <label class="block text-xs font-semibold text-gray-600 mb-2">Options (Check box to mark as correct answer):</label>
                            @foreach($q['options'] as $oIndex => $option)
                                <div class="flex items-center gap-2 mb-2">
                                    <input type="checkbox" 
                                        wire:click="toggleCorrectAnswer({{ $qIndex }}, {{ $oIndex }})" 
                                        @if(in_array($oIndex, $q['correct_answers'] ?? [])) checked @endif 
                                        class="w-4 h-4 text-indigo-600 rounded cursor-pointer" 
                                        title="Mark as correct answer">
                                    
                                    <input type="text" wire:model="questions.{{ $qIndex }}.options.{{ $oIndex }}" class="flex-1 border-gray-300 rounded p-1.5 border text-sm bg-white" style="outline: none;" placeholder="Option {{ chr(65 + $oIndex) }}">
                                    
                                    @if(count($q['options']) > 2)
                                        <button type="button" wire:click="removeOption({{ $qIndex }}, {{ $oIndex }})" class="text-gray-400 hover:text-red-500 p-1 text-sm font-bold">
                                            ✕
                                        </button>
                                    @endif
                                </div>
                                @error('questions.'.$qIndex.'.options.'.$oIndex) <span class="text-red-500 text-xs block mb-1">{{ $message }}</span> @enderror
                            @endforeach

                            <button type="button" wire:click="addOption({{ $qIndex }})" class="text-xs text-indigo-600 font-semibold hover:underline mt-1">
                                + Add Option
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

    @endif

    <div class="mb-6 pt-4 border-t border-gray-200">
        <label class="flex items-center cursor-pointer">
            <input type="checkbox" wire:model="isExercise" class="w-4 h-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500" style="width: 18px; height: 18px; cursor: pointer;">
            <span class="ml-2 text-sm font-semibold text-gray-800">Mark this content as an Exercise</span>
        </label>
        <p class="text-xs text-gray-500 mt-1 ml-6">Exercises require students to upload a file or submit an answer link before completing.</p>
    </div>

    <div class="flex justify-end space-x-3 gap-3">
        <a href="{{ route('content.show', $moduleContentId) }}" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50 text-decoration-none inline-block">Cancel</a>
        <button wire:click="save" class="px-4 py-2 bg-indigo-600 border border-transparent rounded-md text-sm font-medium text-white hover:bg-indigo-700 cursor-pointer border-0">{{ $isEditing ? 'Update Content' : 'Save Content' }}</button>
    </div>
</div>

<div class="{{ $type === 'pdf' ? 'w-[50%]' : 'hidden' }}" style=" flex-shrink: 0; position: sticky; top: 24px;">
    <div class="p-4 bg-white shadow-md rounded-lg border border-gray-200" wire:ignore id="pdf-form-preview-wrapper">
        <label class="block text-sm font-medium text-gray-700 mb-2">
            Preview <span class="font-normal text-gray-500">(how this will appear to students)</span>
        </label>

        <div style="display: flex; justify-content: space-between; align-items: center; gap: 15px; margin-bottom: 10px; background: #F3F4F6; padding: 10px; border-radius: 8px; border: 1px solid #E5E7EB;">
            <span id="pdf-form-page-count" style="font-size: 13px; color: #374151; font-weight: 500;">No PDF selected yet</span>
            <div style="display: flex; gap: 10px; align-items: center;">
                <button type="button" id="pdf-form-zoom-out" style="padding: 4px 10px; background: white; border: 1px solid #D1D5DB; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: 500; color: #374151;">- Zoom Out</button>
                <span id="pdf-form-zoom-level" style="font-weight: bold; color: #111827; min-width: 40px; text-align: center; font-size: 12px;">100%</span>
                <button type="button" id="pdf-form-zoom-in" style="padding: 4px 10px; background: white; border: 1px solid #D1D5DB; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: 500; color: #374151;">+ Zoom In</button>
            </div>
        </div>

        <div style="width: 100%; max-height: 65vh; overflow: auto; background: #F3F4F6; border-radius: 8px; border: 1px solid #E5E7EB;">
            <div id="pdf-form-preview-container" style="display: flex; flex-direction: column; gap: 15px; align-items: center; padding: 15px; min-width: min-content;">
                <p style="color: #9CA3AF; font-weight: 500; font-size: 13px; text-align: center;">Select or upload a PDF above to preview it here.</p>
            </div>
        </div>
    </div>
</div>

@script
<script>
    (function() {
        const container = document.getElementById('pdf-form-preview-container');
        const pageCountLabel = document.getElementById('pdf-form-page-count');
        const zoomInBtn = document.getElementById('pdf-form-zoom-in');
        const zoomOutBtn = document.getElementById('pdf-form-zoom-out');
        const zoomLevelLabel = document.getElementById('pdf-form-zoom-level');

        let currentScale = 1.0;
        let loadedPdf = null;
        let currentUrl = null;
        let currentStart = null;
        let currentEnd = null;
        let currentStartPercent = 0;
        let currentEndPercent = 100;

        function insertSorted(el, pageNum) {
            el.dataset.page = pageNum;
            let inserted = false;
            for (let i = 0; i < container.children.length; i++) {
                if (parseInt(container.children[i].dataset.page) > pageNum) {
                    container.insertBefore(el, container.children[i]);
                    inserted = true;
                    break;
                }
            }
            if (!inserted) container.appendChild(el);
        }

        function renderPages(pdf, scale) {
            const startPage = currentStart ? parseInt(currentStart) : 1;
            let endPage = currentEnd ? parseInt(currentEnd) : pdf.numPages;
            if (isNaN(startPage) || startPage < 1) return;
            if (isNaN(endPage) || endPage > pdf.numPages) endPage = pdf.numPages;

            container.innerHTML = '';

            for (let pageNum = startPage; pageNum <= endPage; pageNum++) {
                pdf.getPage(pageNum).then(function(page) {
                    const viewport = page.getViewport({ scale: scale });
                    const canvas = document.createElement('canvas');
                    const ctx = canvas.getContext('2d');
                    canvas.height = viewport.height;
                    canvas.width = viewport.width;
                    canvas.style.display = 'block';

                    page.render({ canvasContext: ctx, viewport: viewport });

                    const topPct = (pageNum === startPage) ? currentStartPercent : 0;
                    const bottomPct = (pageNum === endPage) ? currentEndPercent : 100;

                    if (topPct > 0 || bottomPct < 100) {
                        const topSkip = viewport.height * (topPct / 100);
                        const visibleHeight = Math.max(0, viewport.height * ((bottomPct - topPct) / 100));

                        const cropWrapper = document.createElement('div');
                        cropWrapper.style.overflow = 'hidden';
                        cropWrapper.style.width = viewport.width + 'px';
                        cropWrapper.style.height = visibleHeight + 'px';
                        cropWrapper.style.boxShadow = '0 4px 6px -1px rgba(0,0,0,0.1)';

                        canvas.style.marginTop = (-topSkip) + 'px';
                        cropWrapper.appendChild(canvas);

                        insertSorted(cropWrapper, pageNum);
                    } else {
                        canvas.style.boxShadow = '0 4px 6px -1px rgba(0,0,0,0.1)';
                        insertSorted(canvas, pageNum);
                    }
                });
            }
        }

        function waitForPdfjsLib(timeoutMs) {
            if (window.pdfjsLib) return Promise.resolve();

            return new Promise((resolve, reject) => {
                const start = Date.now();
                const poll = setInterval(() => {
                    if (window.pdfjsLib) {
                        clearInterval(poll);
                        resolve();
                    } else if (Date.now() - start > timeoutMs) {
                        clearInterval(poll);
                        reject(new Error('pdf.js library failed to load'));
                    }
                }, 100);
            });
        }

        function loadPdfWithTimeout(url, timeoutMs) {
            return waitForPdfjsLib(timeoutMs).then(() => loadPdfDocument(url, timeoutMs));
        }

        function loadPdfDocument(url, timeoutMs) {
            const task = pdfjsLib.getDocument(url);
            let settled = false;

            return new Promise((resolve, reject) => {
                const timer = setTimeout(() => {
                    if (settled) return;
                    settled = true;
                    task.destroy();
                    reject(new Error('pdf.js load timed out'));
                }, timeoutMs);

                task.promise.then((pdf) => {
                    if (settled) return;
                    settled = true;
                    clearTimeout(timer);
                    resolve(pdf);
                }).catch((err) => {
                    if (settled) return;
                    settled = true;
                    clearTimeout(timer);
                    reject(err);
                });
            });
        }

        function fetchPdfWithRetry(url, attemptsLeft) {
            return loadPdfWithTimeout(url, 4000).catch((err) => {
                if (attemptsLeft > 1) return fetchPdfWithRetry(url, attemptsLeft - 1);
                throw err;
            });
        }

        function loadAndRender(data) {
            currentStart = data.startPage;
            currentEnd = data.endPage;
            currentStartPercent = data.startPercent;
            currentEndPercent = data.endPercent;

            if (!data.url) {
                currentUrl = null;
                loadedPdf = null;
                pageCountLabel.textContent = 'No PDF selected yet';
                container.innerHTML = '<p style="color: #9CA3AF; font-weight: 500; font-size: 13px; text-align: center;">Select or upload a PDF above to preview it here.</p>';
                return;
            }

            if (data.url === currentUrl && loadedPdf) {
                renderPages(loadedPdf, currentScale);
                return;
            }

            const requestedUrl = data.url;
            currentUrl = requestedUrl;
            loadedPdf = null;
            pageCountLabel.textContent = 'Loading page count…';
            container.innerHTML = '<p style="color: #6B7280; font-weight: 500; font-size: 13px;">Loading preview…</p>';

            fetchPdfWithRetry(requestedUrl, 3).then(function(pdf) {
                if (requestedUrl !== currentUrl) return;
                loadedPdf = pdf;
                pageCountLabel.textContent = 'This PDF has ' + pdf.numPages + ' page' + (pdf.numPages === 1 ? '' : 's') + '.';
                renderPages(pdf, currentScale);
            }).catch(function(err) {
                if (requestedUrl !== currentUrl) return;
                pageCountLabel.textContent = 'Unable to load PDF preview.';
                container.innerHTML = '<p style="color: #DC2626; font-size: 13px;">Failed to load PDF preview.</p>';
                console.error(err);
            });
        }

        zoomInBtn.addEventListener('click', function() {
            currentScale += 0.25;
            zoomLevelLabel.textContent = Math.round(currentScale * 100) + '%';
            if (loadedPdf) renderPages(loadedPdf, currentScale);
        });

        zoomOutBtn.addEventListener('click', function() {
            if (currentScale > 0.5) {
                currentScale -= 0.25;
                zoomLevelLabel.textContent = Math.round(currentScale * 100) + '%';
                if (loadedPdf) renderPages(loadedPdf, currentScale);
            }
        });

        $wire.on('pdf-preview-changed', (event) => loadAndRender(event));

        loadAndRender({
            url: @js($type === 'pdf' ? $this->pdfPreviewUrl() : null),
            startPage: @js($pdfStartPage),
            endPage: @js($pdfEndPage),
            startPercent: @js((int) $pdfStartPercentage),
            endPercent: @js((int) $pdfEndPercentage),
        });
    })();
</script>
@endscript

</div>
</div>
