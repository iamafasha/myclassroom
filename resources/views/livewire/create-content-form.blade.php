<?php

use App\Models\Content;
use App\Models\ModuleContent;
use App\Models\NoteContent;
use App\Models\PdfNotesContent;
use App\Models\VideoContent;
use App\Models\ImageContent;
use App\Models\LinkContent;
use App\Models\QuizContent;
use App\Models\LiveClassContent;
use App\Models\SessionContent;
use App\Models\File;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public $moduleContentId;
    public $moduleId;
    #[Url]
    public $type = 'note';

    public $contentId = null;
    public $isEditing = false;

    public $label = '';
    /** Day the learner should start on this content. Blank means "no planned date". */
    public $studyAt = '';
    public $noteText = '';
    public $courseId;

    public $pdfFileId = '';
    public $pdfStartPage = '';
    public $pdfEndPage = '';
    public $pdfStartPercentage = 0;
    public $pdfEndPercentage = 100;

    public $videoFileId = '';
    public $videoSourceType = 'file'; // 'file' or 'url'
    public $videoExternalUrl = '';
    public $videoStartTime = '';
    public $videoEndTime = '';

    public $imageFileId = '';
    public $imageSourceType = 'file';
    public $imageExternalUrl;

    public $linkUrl = '';
    public $linkDescription = '';
    public $isExercise = false;

    public $quizDescription = '';
    public $questions = [];

    public $liveClassLink = '';
    public $liveClassJoinEnabled = true;
    public $liveClassStartsAt = '';
    public $liveClassDuration = 60;
    public $liveClassDescription = '';

    public $sessionDescription = '';
    public $sessionDuration = 30;
    public $sessionBookingEnabled = true;
    public $sessionAllowMultiple = false;
    public $sessionMeetingLink = '';
    /** Times students can book straight away. Empty means they request a time instead. */
    public $sessionSlots = [];

    public function mount($moduleContentId, $contentId = null)
    {
        $moduleContent = ModuleContent::findOrFail($moduleContentId);

        abort_unless(
            (bool) $moduleContent->module?->course?->isManagedBy(auth()->user()),
            403,
            'Only the course owner can add or edit content.'
        );

        $this->moduleContentId = $moduleContentId;
        $this->moduleId = $moduleContent->module_id;
        $this->courseId = $moduleContent->module->course_id;
        $this->label = $moduleContent->label ?? '';
        $this->studyAt = $moduleContent->study_at?->format('Y-m-d') ?? '';

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

    /**
     * The picker lists are computed per render rather than held in state, so a file uploaded
     * from inside a picker shows up on the next round trip.
     */
    #[Computed]
    public function pdfFiles()
    {
        return $this->filesForPicker(['pdf']);
    }

    #[Computed]
    public function videoFiles()
    {
        return $this->filesForPicker(['video', 'mp4', 'mov', 'avi']);
    }

    #[Computed]
    public function imageFiles()
    {
        return $this->filesForPicker(['image', 'png', 'jpg', 'jpeg']);
    }

    private function filesForPicker(array $types): array
    {
        return File::ownedBy(auth()->user())
            ->whereIn('file_type', $types)
            ->latest()
            ->get()
            ->map(fn (File $file) => $file->pickerEntry())
            ->all();
    }

    private function loadContentable($contentable)
    {
        $storagePrefix = asset('storage') . '/';

        $resolveFile = function ($fileUrl) use ($storagePrefix) {
            if (!str_starts_with($fileUrl, $storagePrefix)) {
                return null;
            }
            return File::ownedBy(auth()->user())
                ->where('file_path', substr($fileUrl, strlen($storagePrefix)))
                ->first();
        };

        if ($contentable instanceof NoteContent) {
            $this->type = 'note';
            $this->noteText = $contentable->content;
        } elseif ($contentable instanceof PdfNotesContent) {
            $this->type = 'pdf';
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
        } elseif ($contentable instanceof LiveClassContent) {
            $this->type = 'live';
            $this->liveClassLink = $contentable->join_link ?? '';
            $this->liveClassJoinEnabled = (bool) $contentable->is_join_enabled;
            $this->liveClassStartsAt = $contentable->starts_at?->format('Y-m-d\\TH:i') ?? '';
            $this->liveClassDuration = $contentable->duration_minutes ?: 60;
            $this->liveClassDescription = $contentable->description ?? '';
        } elseif ($contentable instanceof SessionContent) {
            $this->type = 'session';
            $this->sessionDescription = $contentable->description ?? '';
            $this->sessionDuration = $contentable->duration_minutes ?: 30;
            $this->sessionBookingEnabled = (bool) $contentable->is_booking_enabled;
            $this->sessionAllowMultiple = (bool) $contentable->allow_multiple;
            $this->sessionMeetingLink = $contentable->meeting_link ?? '';
            // Only times still ahead are worth editing; past ones drop off on save.
            $this->sessionSlots = $contentable->slots()->map(fn ($slot) => $slot->format('Y-m-d\TH:i'))->all();
        }
    }

    public function addSessionSlot()
    {
        if (count($this->sessionSlots) < 10) {
            $this->sessionSlots[] = '';
        }
    }

    public function removeSessionSlot($index)
    {
        unset($this->sessionSlots[$index]);
        $this->sessionSlots = array_values($this->sessionSlots);
    }

    /** A picked file must be one the current user uploaded. */
    private function ownedFileRule()
    {
        return \Illuminate\Validation\Rule::exists('files', 'id')->where('user_id', auth()->id());
    }

    public function pdfPreviewUrl()
    {
        if (! $this->pdfFileId) {
            return null;
        }

        $file = File::ownedBy(auth()->user())->find($this->pdfFileId);

        return $file ? asset('storage/' . $file->file_path) : null;
    }

    public function updated($name)
    {
        $pdfPreviewFields = ['pdfStartPage', 'pdfEndPage', 'pdfStartPercentage', 'pdfEndPercentage', 'pdfFileId'];

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
            'studyAt' => 'nullable|date',
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
            $this->validate([
                'pdfFileId' => ['required', $this->ownedFileRule()],
                'pdfStartPage' => 'nullable|string',
                'pdfEndPage' => 'nullable|string',
            ]);

            $file = File::ownedBy(auth()->user())->find($this->pdfFileId);

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
                    'videoFileId' => ['required', $this->ownedFileRule()],
                    'videoStartTime' => 'nullable|string',
                    'videoEndTime' => 'nullable|string',
                ]);

                $file = File::ownedBy(auth()->user())->find($this->videoFileId);
                $fileUrl = asset('storage/' . $file->file_path);
                $startTime = $this->videoStartTime;
                $endTime = $this->videoEndTime;
            } else {
                $this->validate([
                    'videoExternalUrl' => 'required|url',
                    'videoStartTime' => 'nullable|string',
                    'videoEndTime' => 'nullable|string',
                ]);

                // Save against the link and let the copy download in the background:
                // fetching it here held the request open until PHP timed out.
                $fileUrl = $this->videoExternalUrl;
                $startTime = $this->videoStartTime;
                $endTime = $this->videoEndTime;
                $downloadFromUrl = true;
            }

            $contentable = $existingContentable ?: new VideoContent();
            $contentable->name = $this->label;
            $contentable->file_url = $fileUrl;
            $contentable->start_time = $startTime;
            $contentable->end_time = $endTime;
            $contentable->save();

            if (! empty($downloadFromUrl)) {
                \App\Jobs\DownloadVideoContent::dispatch($contentable->id, $fileUrl, $startTime ?: null, $endTime ?: null);
            }
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
                    // Only the chosen source is validated — the other one is left empty on purpose.
                    'imageFileId' => $this->imageSourceType === 'file' ? ['required', $this->ownedFileRule()] : ['nullable'],
                    'imageExternalUrl' => $this->imageSourceType === 'url' ? ['required', 'url'] : ['nullable'],
                ]);

                $contentable = $existingContentable ?: new ImageContent();
                $contentable->name = $this->label;

                if($this->imageSourceType == 'file'){
                    $file = File::ownedBy(auth()->user())->find($this->imageFileId);
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
        } elseif ($this->type === 'live') {
            $this->validate([
                'liveClassStartsAt' => 'required|date',
                'liveClassDuration' => 'required|integer|min:5|max:1440',
                'liveClassLink' => 'nullable|url|max:2048',
                'liveClassDescription' => 'nullable|string',
            ], [
                'liveClassStartsAt.required' => 'Please pick the date and time of the class.',
                'liveClassLink.url' => 'The class link must be a valid URL (e.g. https://meet.google.com/...).',
            ]);

            $contentable = $existingContentable ?: new LiveClassContent();
            $contentable->title = $this->label;
            $contentable->description = $this->liveClassDescription ?: null;
            $contentable->join_link = trim($this->liveClassLink) ?: null;
            $contentable->is_join_enabled = (bool) $this->liveClassJoinEnabled;
            $contentable->starts_at = \Illuminate\Support\Carbon::parse($this->liveClassStartsAt);
            $contentable->duration_minutes = (int) $this->liveClassDuration ?: 60;
            $contentable->save();
        } elseif ($this->type === 'session') {
            // Blank rows are unused inputs, not an error.
            $this->sessionSlots = array_values(array_filter($this->sessionSlots, fn ($slot) => trim((string) $slot) !== ''));

            $this->validate([
                'sessionDuration' => 'required|integer|min:10|max:240',
                'sessionMeetingLink' => 'nullable|url|max:2048',
                'sessionDescription' => 'nullable|string',
                'sessionSlots' => 'nullable|array|max:10',
                'sessionSlots.*' => 'required|date|after:now',
            ], [
                'sessionSlots.*.required' => 'Fill in this time or remove the row.',
                'sessionSlots.*.after' => 'Bookable times must be in the future.',
                'sessionMeetingLink.url' => 'The meeting link must be a valid URL (e.g. https://meet.google.com/...).',
            ]);

            $slots = collect($this->sessionSlots)
                ->map(fn ($slot) => \Illuminate\Support\Carbon::parse($slot)->format('Y-m-d H:i:00'))
                ->unique()
                ->sort()
                ->values()
                ->all();

            $contentable = $existingContentable ?: new SessionContent();
            $contentable->title = $this->label;
            $contentable->description = $this->sessionDescription ?: null;
            $contentable->duration_minutes = (int) $this->sessionDuration ?: 30;
            $contentable->is_booking_enabled = (bool) $this->sessionBookingEnabled;
            $contentable->allow_multiple = (bool) $this->sessionAllowMultiple;
            $contentable->meeting_link = trim($this->sessionMeetingLink) ?: null;
            $contentable->available_slots = $slots ?: null;
            $contentable->save();
        }

        if ($this->isEditing) {
            $moduleContent = ModuleContent::findOrFail($this->moduleContentId);
            $moduleContent->label = $this->label;
            $moduleContent->study_at = $this->studyAt ?: null;
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
            $moduleContent->study_at = $this->studyAt ?: null;
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
<x-pdf-viewer-engine />
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
            <option value="live">Live Class</option>
            <option value="session">Mentor Session</option>
        </select>
    </div>
    
    <div class="mb-4">
        <label class="block text-sm font-medium text-gray-700 mb-1">Content Label</label>
        <input type="text" wire:model="label" class="w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 p-2 border" style="outline: none;" placeholder="e.g. Introduction Note">
        @error('label') <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> @enderror
    </div>

    <div class="mb-4">
        <label class="block text-sm font-medium text-gray-700 mb-1">Start Date <span class="font-normal text-gray-500">(optional)</span></label>
        <input type="date" wire:model="studyAt" class="w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 p-2 border" style="outline: none;">
        <p class="text-xs text-gray-500 mt-1">When learners should start reading or studying this content. Leave blank for no planned date.</p>
        @error('studyAt') <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> @enderror
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
                <label class="block text-sm font-medium text-gray-700 mb-1">PDF File</label>
                <x-file-picker model="pdfFileId" kind="pdf" :files="$this->pdfFiles" :live="true" />
                @error('pdfFileId') <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> @enderror
            </div>

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
                    <input type="range" min="0" max="100" step="1" wire:model.live.debounce.300ms="pdfStartPercentage" class="w-full">
                    <p class="text-xs text-gray-500 mt-1">Crops the top of the first page shown. 0% shows the whole page from the top.</p>
                    @error('pdfStartPercentage') <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                </div>
                <div class="w-1/2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        End Page &mdash; Show Up To <span class="font-semibold text-indigo-600">{{ $pdfEndPercentage }}%</span> Down
                    </label>
                    <input type="range" min="0" max="100" step="1" wire:model.live.debounce.300ms="pdfEndPercentage" class="w-full">
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
                    <label class="block text-sm font-medium text-gray-700 mb-1">Video File</label>
                    <x-file-picker model="videoFileId" kind="video" :files="$this->videoFiles" />
                    @error('videoFileId') <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                </div>

      
            @else
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Video URL (YouTube link, MP4 link, etc.)</label>
                    <input type="url" wire:model="videoExternalUrl" class="w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 p-2 border" style="outline: none;" placeholder="https://www.youtube.com/watch?v=...">
                    @error('videoExternalUrl') <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                    <p class="text-xs text-gray-500 mt-1">Saves right away and plays from the link. A copy is fetched in the background and takes over once it is ready.</p>
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
                <label class="block text-sm font-medium text-gray-700 mb-1">Image File</label>
                <x-file-picker model="imageFileId" kind="image" :files="$this->imageFiles" />
                @error('imageFileId') <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> @enderror
            </div>
            @else
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Image URL</label>
                    <input type="url" wire:model="imageExternalUrl" class="w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 p-2 border" style="outline: none;" placeholder="https://....jpg">
                    @error('imageExternalUrl') <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                </div>
            @endif

        @elseif($type === 'live')
            <div class="mb-4 p-4 rounded-lg border" style="background: #F5F3FF; border-color: #DDD6FE;">
                <div class="flex items-center gap-2 mb-3">
                    <span style="font-size: 1.1rem;">🔴</span>
                    <h3 class="text-base font-semibold text-gray-800" style="margin: 0;">Live Class Session</h3>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Date &amp; time</label>
                        <input type="datetime-local" wire:model="liveClassStartsAt" class="w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 p-2 border bg-white" style="outline: none;">
                        @error('liveClassStartsAt') <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Duration (minutes)</label>
                        <input type="number" min="5" max="1440" wire:model="liveClassDuration" class="w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 p-2 border bg-white" style="outline: none;" placeholder="60">
                        @error('liveClassDuration') <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="mt-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Class link <span class="font-normal text-gray-500">(optional)</span></label>
                    <input type="url" wire:model="liveClassLink" class="w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 p-2 border bg-white" style="outline: none;" placeholder="https://meet.google.com/... or https://zoom.us/j/...">
                    @error('liveClassLink') <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                    <p class="text-xs text-gray-500 mt-1">Leave empty if you will share the link another way — students still see the schedule.</p>
                </div>

                <div class="mt-4 pt-4" style="border-top: 1px solid #DDD6FE;">
                    <label class="flex items-center cursor-pointer">
                        <input type="checkbox" wire:model.live="liveClassJoinEnabled" class="w-4 h-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500" style="width: 18px; height: 18px; cursor: pointer;">
                        <span class="ml-2 text-sm font-semibold text-gray-800">Allow students to join</span>
                    </label>
                    <p class="text-xs text-gray-500 mt-1 ml-6">
                        @if($liveClassJoinEnabled)
                            Students see the Join button once a link is set. Switch off to hide the link until you are ready.
                        @else
                            Joining is closed — students see the schedule only, even if a link is saved.
                        @endif
                    </p>
                </div>
            </div>

            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 mb-1">What will be covered (Optional)</label>
                <textarea wire:model="liveClassDescription" rows="4" class="w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 p-2 border" style="outline: none;" placeholder="Agenda, what to prepare before joining..."></textarea>
                @error('liveClassDescription') <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> @enderror
            </div>
        @elseif($type === 'session')
            <div class="mb-4 p-4 rounded-lg border" style="background: #FAF5FF; border-color: #DDD6FE;">
                <div class="flex items-center gap-2 mb-1">
                    <span style="font-size: 1.1rem;">🧑‍🏫</span>
                    <h3 class="text-base font-semibold text-gray-800" style="margin: 0;">One-to-one Mentor Session</h3>
                </div>
                <p class="text-xs text-gray-500 mb-4">
                    This block holds no single session. Every student who opens it books their own session with you, and manages it from inside the lesson.
                </p>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Session length (minutes)</label>
                        <input type="number" min="10" max="240" wire:model="sessionDuration" class="w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 p-2 border bg-white" style="outline: none;" placeholder="30">
                        @error('sessionDuration') <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Meeting link <span class="font-normal text-gray-500">(optional)</span></label>
                        <input type="url" wire:model="sessionMeetingLink" class="w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 p-2 border bg-white" style="outline: none;" placeholder="https://meet.google.com/...">
                        @error('sessionMeetingLink') <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="mt-4 pt-4" style="border-top: 1px solid #DDD6FE;">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Times students can book instantly <span class="font-normal text-gray-500">(optional)</span></label>
                    <p class="text-xs text-gray-500 mb-3">
                        Leave empty and students send you a request instead — you then offer them times to choose from. A time disappears once someone books it, and past times drop off when you save.
                    </p>

                    @foreach($sessionSlots as $index => $slot)
                        <div wire:key="session-slot-{{ $index }}" class="mb-2">
                            <div class="flex items-center gap-2">
                                <input type="datetime-local" wire:model="sessionSlots.{{ $index }}" class="flex-1 border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 p-2 border bg-white" style="outline: none;">
                                <button type="button" wire:click="removeSessionSlot({{ $index }})" title="Remove this time" class="text-gray-400 hover:text-red-500 p-1 text-sm font-bold cursor-pointer" style="background: none; border: none;">✕</button>
                            </div>
                            @error('sessionSlots.' . $index) <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                        </div>
                    @endforeach

                    @if(count($sessionSlots) < 10)
                        <button type="button" wire:click="addSessionSlot" class="text-xs text-indigo-600 font-semibold hover:underline mt-1 cursor-pointer" style="background: none; border: none; padding: 0;">
                            + Add a bookable time
                        </button>
                    @endif
                </div>

                <div class="mt-4 pt-4" style="border-top: 1px solid #DDD6FE;">
                    <label class="flex items-center cursor-pointer">
                        <input type="checkbox" wire:model.live="sessionBookingEnabled" class="w-4 h-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500" style="width: 18px; height: 18px; cursor: pointer;">
                        <span class="ml-2 text-sm font-semibold text-gray-800">Students can book sessions here</span>
                    </label>
                    <p class="text-xs text-gray-500 mt-1 ml-6">
                        @if($sessionBookingEnabled)
                            Students see the booking button. Switch off to pause new bookings — sessions already booked are unaffected.
                        @else
                            New bookings are closed. Sessions already booked stay as they are.
                        @endif
                    </p>

                    <label class="flex items-center cursor-pointer mt-3">
                        <input type="checkbox" wire:model.live="sessionAllowMultiple" class="w-4 h-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500" style="width: 18px; height: 18px; cursor: pointer;">
                        <span class="ml-2 text-sm font-semibold text-gray-800">Allow several open sessions per student</span>
                    </label>
                    <p class="text-xs text-gray-500 mt-1 ml-6">
                        Off means a student holds one session at a time here — they can book again once it is done or cancelled.
                    </p>
                </div>
            </div>

            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 mb-1">What this session is for (Optional)</label>
                <textarea wire:model="sessionDescription" rows="4" class="w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 p-2 border" style="outline: none;" placeholder="What students should bring, what you will go through together..."></textarea>
                @error('sessionDescription') <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> @enderror
            </div>
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

        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 10px; background: #F3F4F6; padding: 10px; border-radius: 8px; border: 1px solid #E5E7EB;">
            <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                <span id="pdf-form-page-count" style="font-size: 13px; color: #374151; font-weight: 500;">No PDF selected yet</span>
                <span id="pdf-form-current-page" style="font-size: 12px; font-weight: 600; color: #111827; background: white; border: 1px solid #D1D5DB; border-radius: 999px; padding: 3px 10px; white-space: nowrap;">Page &ndash;</span>
            </div>
            <div style="display: flex; gap: 8px; align-items: center;">
                <button type="button" id="pdf-form-zoom-out" style="padding: 4px 10px; background: white; border: 1px solid #D1D5DB; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: 500; color: #374151;" aria-label="Zoom out">&minus;</button>
                <span id="pdf-form-zoom-level" style="font-weight: bold; color: #111827; min-width: 44px; text-align: center; font-size: 12px;">100%</span>
                <button type="button" id="pdf-form-zoom-in" style="padding: 4px 10px; background: white; border: 1px solid #D1D5DB; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: 500; color: #374151;" aria-label="Zoom in">+</button>
                <button type="button" id="pdf-form-zoom-fit" style="padding: 4px 10px; background: white; border: 1px solid #D1D5DB; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: 500; color: #374151;">Fit width</button>
            </div>
        </div>

        <div id="pdf-form-preview-scroll" style="width: 100%; max-height: 65vh; overflow: auto; background: #F3F4F6; border-radius: 8px; border: 1px solid #E5E7EB; touch-action: pan-x pan-y;">
            <div id="pdf-form-preview-sizer" style="width: max-content; margin: 0 auto;">
                <div id="pdf-form-preview-container" style="display: flex; flex-direction: column; gap: 15px; align-items: center; padding: 15px;">
                    <p style="color: #9CA3AF; font-weight: 500; font-size: 13px; text-align: center;">Select or upload a PDF above to preview it here.</p>
                </div>
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
        const zoomFitBtn = document.getElementById('pdf-form-zoom-fit');

        const PLACEHOLDER = '<p style="color: #9CA3AF; font-weight: 500; font-size: 13px; text-align: center;">Select or upload a PDF above to preview it here.</p>';

        let viewer = null;
        let loadedPdf = null;
        let currentUrl = null;

        // The preview box is its own scroll container, so the engine anchors zooming to it.
        function pdfViewer() {
            if (!viewer) {
                viewer = window.createPdfViewer({
                    wrapper: document.getElementById('pdf-form-preview-scroll'),
                    sizer: document.getElementById('pdf-form-preview-sizer'),
                    container: container,
                    levelEl: document.getElementById('pdf-form-zoom-level'),
                    pageNoEl: document.getElementById('pdf-form-current-page'),
                    verticalScroll: 'self'
                });
            }
            return viewer;
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

        function show(pdf, data) {
            pdfViewer().setDocument(pdf, {
                startPage: data.startPage ? parseInt(data.startPage) : 1,
                endPage: data.endPage ? parseInt(data.endPage) : null,
                startPercent: data.startPercent,
                endPercent: data.endPercent
            });
        }

        function loadAndRender(data) {
            if (!data.url) {
                currentUrl = null;
                loadedPdf = null;
                pageCountLabel.textContent = 'No PDF selected yet';
                pdfViewer().message(PLACEHOLDER);
                return;
            }

            if (data.url === currentUrl && loadedPdf) {
                show(loadedPdf, data);
                return;
            }

            const requestedUrl = data.url;
            currentUrl = requestedUrl;
            loadedPdf = null;
            pageCountLabel.textContent = 'Loading page count…';
            pdfViewer().message('<p style="color: #6B7280; font-weight: 500; font-size: 13px;">Loading preview…</p>');

            fetchPdfWithRetry(requestedUrl, 3).then(function(pdf) {
                if (requestedUrl !== currentUrl) return;
                loadedPdf = pdf;
                pageCountLabel.textContent = 'This PDF has ' + pdf.numPages + ' page' + (pdf.numPages === 1 ? '' : 's') + '.';
                show(pdf, data);
            }).catch(function(err) {
                if (requestedUrl !== currentUrl) return;
                pageCountLabel.textContent = 'Unable to load PDF preview.';
                pdfViewer().message('<p style="color: #DC2626; font-size: 13px;">Failed to load PDF preview.</p>');
                console.error(err);
            });
        }

        zoomInBtn.addEventListener('click', () => pdfViewer().zoomBy(1.25));
        zoomOutBtn.addEventListener('click', () => pdfViewer().zoomBy(1 / 1.25));
        zoomFitBtn.addEventListener('click', () => pdfViewer().fitWidth());

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
