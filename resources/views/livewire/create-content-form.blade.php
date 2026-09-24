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

    /**
     * Rendered inside the reading page instead of on its own. The lesson's title and date
     * are edited in that page's header then, so the form leaves them alone.
     */
    public $inline = false;

    /** A new block goes right after this one; null adds it at the end. */
    public $insertAfter = null;

    /** When the last autosave landed, for the "Saved" indicator. */
    public $savedAt = null;

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
    /** Most PDFs are shown as whole pages, so cropping stays hidden until asked for. */
    public $pdfCropEnabled = false;
    /** Reported by the preview once the PDF loads; only used to bound the page inputs. */
    public $pdfPageCount = null;

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

    public function mount($moduleContentId, $contentId = null, $inline = false, $type = null, $insertAfter = null)
    {
        $this->inline = (bool) $inline;
        $this->insertAfter = $insertAfter ?? request()->query('after');
        if ($type) {
            $this->type = $type;
        }

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
        return $this->filesForPicker(['video', 'mp4', 'mov', 'avi', 'webm']);
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
            $this->pdfCropEnabled = (int) $this->pdfStartPercentage > 0 || (int) $this->pdfEndPercentage < 100;
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
        // Picking a file with no label yet borrows the file's name, so the common case
        // of "label matches the document" needs no typing. A label already set is left alone.
        if (in_array($name, ['pdfFileId', 'videoFileId', 'imageFileId'], true) && trim((string) $this->label) === '') {
            $fileId = $this->{$name};

            if ($fileId) {
                $file = File::ownedBy(auth()->user())->find($fileId);

                if ($file) {
                    $this->label = preg_replace('/\.[^.]+$/', '', $file->name);
                }
            }
        }

        // Live-bound fields report themselves here; the rest are autosaved from the page.
        if ($this->isEditing && ! in_array($name, ['pdfPageCount', 'savedAt'], true)) {
            $this->autosave();
        }

        // A different document starts from its first page again, with a page count still to come.
        if ($name === 'pdfFileId') {
            $this->pdfPageCount = null;
            $this->pdfStartPage = '';
            $this->pdfEndPage = '';
        }

        // Switching cropping off means whole pages again, not a hidden crop still applied.
        if ($name === 'pdfCropEnabled' && ! $this->pdfCropEnabled) {
            $this->pdfStartPercentage = 0;
            $this->pdfEndPercentage = 100;
        }

        $pdfPreviewFields = ['pdfStartPage', 'pdfEndPage', 'pdfStartPercentage', 'pdfEndPercentage', 'pdfFileId', 'pdfCropEnabled'];

        if ($name === 'type' || ($this->type === 'pdf' && in_array($name, $pdfPreviewFields))) {
            $this->dispatch('pdf-preview-changed', ...$this->pdfPreviewState());
        }

        $videoPreviewFields = ['videoFileId', 'videoSourceType', 'videoExternalUrl', 'videoStartTime', 'videoEndTime'];

        if ($name === 'type' || ($this->type === 'video' && in_array($name, $videoPreviewFields))) {
            $this->dispatch('video-preview-changed', ...$this->videoPreviewState());
        }
    }

    /**
     * What the video preview plays: the picked file or the pasted link, with the start and
     * end times as seconds (null when blank or not a time yet).
     */
    public function videoPreviewState(): array
    {
        $url = null;

        if ($this->type === 'video') {
            if ($this->videoSourceType === 'file') {
                $file = $this->videoFileId ? File::ownedBy(auth()->user())->find($this->videoFileId) : null;
                $url = $file ? asset('storage/' . $file->file_path) : null;
            } elseif (filter_var(trim((string) $this->videoExternalUrl), FILTER_VALIDATE_URL)) {
                $url = trim((string) $this->videoExternalUrl);
            }
        }

        return [
            'url' => $url,
            'youtubeId' => \App\Support\QuickBlock::youtubeId($url),
            'start' => $this->secondsFrom($this->videoStartTime),
            'end' => $this->secondsFrom($this->videoEndTime),
        ];
    }

    /** Times the student player understands: minutes:seconds or hours:minutes:seconds. */
    private const VIDEO_TIME_PATTERN = '/^\d+:[0-5]\d(:[0-5]\d)?$/';

    /** "01:20" or "1:02:03" as seconds; null for anything the player would ignore. */
    private function secondsFrom($time): ?int
    {
        $time = trim((string) $time);

        if (! preg_match(self::VIDEO_TIME_PATTERN, $time)) {
            return null;
        }

        return array_reduce(explode(':', $time), fn ($total, $part) => $total * 60 + (int) $part, 0);
    }

    public function messages(): array
    {
        return [
            'videoStartTime.regex' => 'Use minutes:seconds, like 01:20 (or 1:02:03 for hours).',
            'videoEndTime.regex' => 'Use minutes:seconds, like 05:30 (or 1:02:03 for hours).',
        ];
    }

    private function videoTimeRules(): array
    {
        return [
            'videoStartTime' => ['nullable', 'string', 'regex:' . self::VIDEO_TIME_PATTERN],
            'videoEndTime' => ['nullable', 'string', 'regex:' . self::VIDEO_TIME_PATTERN],
        ];
    }

    private function checkVideoTimeOrder(): void
    {
        $start = $this->secondsFrom($this->videoStartTime);
        $end = $this->secondsFrom($this->videoEndTime);

        if ($start !== null && $end !== null && $end <= $start) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'videoEndTime' => 'The end time has to come after the start time.',
            ]);
        }
    }

    /** Everything the preview needs to draw the current page range and crop. */
    public function pdfPreviewState(): array
    {
        return [
            'url' => $this->type === 'pdf' ? $this->pdfPreviewUrl() : null,
            'startPage' => $this->pdfStartPage,
            'endPage' => $this->pdfEndPage,
            'startPercent' => (int) $this->pdfStartPercentage,
            'endPercent' => (int) $this->pdfEndPercentage,
            'cropEditing' => (bool) $this->pdfCropEnabled,
        ];
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
        if (! $this->persist()) {
            return;
        }

        if ($this->inline) {
            $this->dispatch('content-editor-closed');
            return;
        }

        return redirect()->route('content.show', $this->moduleContentId);
    }

    /**
     * Editing saves as you go: the page calls this a moment after each change. A change
     * that doesn't validate yet just isn't saved, and the errors say why.
     */
    public function autosave()
    {
        if (! $this->isEditing) {
            return;
        }

        // The reading page catches up when the editor closes: refreshing it mid-edit
        // would redraw this form's PDF preview out from under the author.
        if ($this->persist()) {
            $this->savedAt = now()->format('H:i');
        }
    }

    /** Leaves the inline editor; anything valid is already saved. */
    public function done()
    {
        if ($this->isEditing && ! $this->persist()) {
            return;
        }

        if ($this->inline) {
            $this->dispatch('content-editor-closed');
            return;
        }

        return redirect()->route('content.show', $this->moduleContentId);
    }

    public function cancel()
    {
        if ($this->inline) {
            $this->dispatch('content-editor-closed');
            return;
        }

        return redirect()->route('content.show', $this->moduleContentId);
    }

    private function persist(): bool
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
                'pdfStartPage' => ['nullable', 'integer', 'min:1', ...($this->pdfPageCount ? ['max:' . (int) $this->pdfPageCount] : [])],
                'pdfEndPage' => ['nullable', 'integer', 'min:1', ...($this->pdfPageCount ? ['max:' . (int) $this->pdfPageCount] : [])],
            ], [
                'pdfStartPage.max' => 'This PDF only has :max pages.',
                'pdfEndPage.max' => 'This PDF only has :max pages.',
            ]);

            if ($this->pdfStartPage !== '' && $this->pdfStartPage !== null && $this->pdfEndPage !== '' && $this->pdfEndPage !== null
                && (int) $this->pdfEndPage < (int) $this->pdfStartPage) {
                $this->addError('pdfEndPage', 'The last page can\'t come before the first page.');
                return false;
            }

            if (! $this->pdfCropEnabled) {
                $this->pdfStartPercentage = 0;
                $this->pdfEndPercentage = 100;
            }

            $file = File::ownedBy(auth()->user())->find($this->pdfFileId);

            $this->validate([
                'pdfStartPercentage' => 'nullable|integer|min:0|max:100',
                'pdfEndPercentage' => 'nullable|integer|min:0|max:100',
            ]);

            if ($this->pdfStartPage !== '' && $this->pdfEndPage !== '' && (int) $this->pdfStartPage === (int) $this->pdfEndPage
                && (int) $this->pdfEndPercentage <= (int) $this->pdfStartPercentage) {
                $this->addError('pdfEndPercentage', 'End crop percentage must be greater than start crop percentage when start and end page are the same.');
                return false;
            }

            $contentable = $existingContentable ?: new PdfNotesContent();
            $contentable->name = $this->label;
            $contentable->file_url = asset('storage/' . $file->file_path);
            // Blank means "from the first page" / "to the last page".
            $contentable->start_position = $this->pdfStartPage === '' ? null : $this->pdfStartPage;
            $contentable->end_position = $this->pdfEndPage === '' ? null : $this->pdfEndPage;
            $contentable->start_percentage = $this->pdfStartPercentage !== '' ? (int) $this->pdfStartPercentage : 0;
            $contentable->end_percentage = $this->pdfEndPercentage !== '' ? (int) $this->pdfEndPercentage : 100;
            $contentable->save();
        } elseif ($this->type === 'video') {
            
            if ($this->videoSourceType === 'file') {
                $this->validate([
                    'videoFileId' => ['required', $this->ownedFileRule()],
                    ...$this->videoTimeRules(),
                ]);

                $this->checkVideoTimeOrder();
                $file = File::ownedBy(auth()->user())->find($this->videoFileId);
                $fileUrl = asset('storage/' . $file->file_path);
                $startTime = $this->videoStartTime;
                $endTime = $this->videoEndTime;
            } else {
                $this->validate([
                    'videoExternalUrl' => 'required|url',
                    ...$this->videoTimeRules(),
                ]);

                $this->checkVideoTimeOrder();
                // Played straight from the link (YouTube or a direct file): never copied to this server.
                $fileUrl = $this->videoExternalUrl;
                $startTime = $this->videoStartTime;
                $endTime = $this->videoEndTime;
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
            if (! $this->inline) {
                $moduleContent->label = $this->label;
                $moduleContent->study_at = $this->studyAt ?: null;
            }
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
            if (! $this->inline) {
                $moduleContent->study_at = $this->studyAt ?: null;
            }
            if (!$moduleContent->slug) {
                $moduleContent->slug = \Illuminate\Support\Str::slug($this->label . '-' . time());
            }
            $moduleContent->save();

            $moduleContent->addBlock($content, (bool) $this->isExercise, $this->insertAfter);

            // Announce it to the classes taking this course. Editing stays quiet:
            // only genuinely new content is worth an email.
            \App\Jobs\NotifyClassOfNewContent::dispatch($moduleContent->id, $content->id, auth()->id());

            // Anything after this edits the block just made, rather than adding another.
            $this->contentId = $content->id;
            $this->isEditing = true;
        }

        return true;
    }
    

};

?>

<div class="ccf-root {{ $inline ? 'ccf-inline' : '' }}" style="{{ $inline ? 'width: 100%;' : 'width: 100%; height: 100%; overflow-y: auto;' }}">
{{-- The engine prints its scripts only once per response, so it is there on some renders
     and not others (inline, the reading page may have printed it already). Its own keyed
     wrapper, and the keys below, keep that from shifting how Livewire matches the rest,
     which would otherwise replace the wire:ignore'd preview mid-edit. --}}
<div wire:key="ccf-engine"><x-pdf-viewer-engine /></div>
<style>
    .ccf-layout { display: flex; align-items: flex-start; gap: 24px; }
    /* Inline, the form sits side by side with the PDF preview too, and only stacks once
       the reading column itself (not the window) gets too narrow for two columns. */
    .ccf-inline { container-type: inline-size; }
    @container (max-width: 760px) {
        .ccf-layout { flex-direction: column; align-items: stretch; }
        .ccf-preview { width: 100% !important; position: static !important; }
    }
    @media (max-width: 900px) {
        .ccf-layout { flex-direction: column; align-items: stretch; }
        .ccf-preview { width: 100% !important; position: static !important; }
    }
    .ccf-status { font-size: 12px; font-weight: 500; color: #6B7280; display: inline-flex; align-items: center; gap: 6px; }
    .ccf-status-dot { width: 7px; height: 7px; border-radius: 999px; background: currentColor; }
    .ccf-num { width: 80px; padding: 4px 6px; border: 1px solid #D1D5DB; border-radius: 6px; font-size: 13px; outline: none; }
    .ccf-num:focus { border-color: #6366F1; box-shadow: 0 0 0 2px rgba(99, 102, 241, .15); }
    .ccf-mark { font: inherit; font-size: 12px; font-weight: 600; color: #4338CA; background: #EEF2FF; border: 1px solid #C7D2FE; border-radius: 6px; padding: 5px 10px; cursor: pointer; }
    .ccf-mark:hover { background: #E0E7FF; }
    .ccf-switch { position: relative; width: 36px; height: 20px; border-radius: 999px; background: #D1D5DB; transition: background .15s ease; flex-shrink: 0; }
    .ccf-switch::after { content: ''; position: absolute; top: 2px; left: 2px; width: 16px; height: 16px; border-radius: 999px; background: #fff; box-shadow: 0 1px 2px rgba(0,0,0,.2); transition: transform .15s ease; }
    .ccf-switch[data-on="true"] { background: #4F46E5; }
    .ccf-switch[data-on="true"]::after { transform: translateX(16px); }
</style>
<div wire:key="ccf-layout" class="ccf-layout {{ $inline ? '' : 'max-w-8xl mx-auto mt-10 mb-10' }}" style="{{ $inline ? '' : 'padding: 0 24px;' }}">

<div class="flex-1 min-w-0 p-6 bg-white rounded-lg border {{ $inline ? 'border-indigo-200' : 'shadow-md border-gray-200' }}"
     x-data="{
        {{-- Deferred fields are sent along with this call. Live-bound ones save themselves
             server side, and the form's own buttons do their own saving. --}}
        maybeAutosave(event) {
            if (! $wire.isEditing) return;
            const target = event.target;
            if (! target || target.closest('[data-no-autosave]')) return;
            if ([...(target.attributes || [])].some(a => a.name.startsWith('wire:model.live'))) return;
            $wire.autosave();
        },
     }"
     x-on:input.debounce.800ms="maybeAutosave($event)"
     x-on:change.debounce.800ms="maybeAutosave($event)"
     x-on:click.debounce.800ms="$event.target.closest('button[wire\\:click]') && maybeAutosave($event)">

    <div class="flex justify-between items-center mb-6" style="gap: 12px; flex-wrap: wrap;">
        @if($inline)
            <h2 class="text-base font-semibold text-gray-800" style="margin: 0;">{{ $isEditing ? 'Editing this block' : 'New block' }}</h2>
        @else
            <h1 class="text-2xl font-bold text-gray-800">{{ $isEditing ? 'Edit Content' : 'Add Content to Module' }}</h1>
        @endif

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
    
    @if(! $inline || trim((string) $label) === '')
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
    @endif

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
                    <label class="block text-sm font-medium text-gray-700 mb-1">From page</label>
                    <input type="number" inputmode="numeric" min="1" @if($pdfPageCount) max="{{ $pdfPageCount }}" @endif step="1"
                           wire:model.live.debounce.500ms="pdfStartPage"
                           class="w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 p-2 border" style="outline: none;"
                           placeholder="1">
                    @error('pdfStartPage') <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                </div>
                <div class="w-1/2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">To page</label>
                    <input type="number" inputmode="numeric" min="1" @if($pdfPageCount) max="{{ $pdfPageCount }}" @endif step="1"
                           wire:model.live.debounce.500ms="pdfEndPage"
                           class="w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 p-2 border" style="outline: none;"
                           placeholder="{{ $pdfPageCount ?: 'Last page' }}">
                    @error('pdfEndPage') <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                </div>
            </div>
            <p class="text-xs text-gray-500" style="margin: -8px 0 16px;">
                Leave these empty to show the whole document{{ $pdfPageCount ? ' (pages 1–' . $pdfPageCount . ')' : '' }}.
            </p>

            <div class="mb-6">
                <label class="inline-flex items-center cursor-pointer" style="gap: 10px;">
                    <input type="checkbox" wire:model.live="pdfCropEnabled" class="sr-only" style="position: absolute; opacity: 0; width: 1px; height: 1px;">
                    <span class="ccf-switch" data-on="{{ $pdfCropEnabled ? 'true' : 'false' }}" aria-hidden="true"></span>
                    <span class="text-sm font-medium text-gray-700">Crop pages</span>
                </label>
                <p class="text-xs text-gray-500 mt-1">Start partway down the first page or stop partway down the last one.</p>

                @if($pdfCropEnabled)
                    <p class="text-xs mt-3" style="color: #4338CA; background: #EEF2FF; border: 1px solid #C7D2FE; border-radius: 6px; padding: 6px 10px;">
                        Drag the handles on the preview's first and last pages, or type exact values below.
                    </p>
                    <div class="flex gap-4 mt-3">
                        <div class="w-1/2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">First page starts at</label>
                            <div class="flex items-center gap-3">
                                <input type="range" min="0" max="100" step="1" wire:model.live.debounce.300ms="pdfStartPercentage" class="flex-1">
                                <span class="flex items-center gap-1">
                                    <input type="number" min="0" max="100" step="1" wire:model.live.debounce.500ms="pdfStartPercentage" class="ccf-num" aria-label="First page starts at, percent">
                                    <span class="text-sm text-gray-500">%</span>
                                </span>
                            </div>
                            @error('pdfStartPercentage') <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                        </div>
                        <div class="w-1/2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Last page ends at</label>
                            <div class="flex items-center gap-3">
                                <input type="range" min="0" max="100" step="1" wire:model.live.debounce.300ms="pdfEndPercentage" class="flex-1">
                                <span class="flex items-center gap-1">
                                    <input type="number" min="0" max="100" step="1" wire:model.live.debounce.500ms="pdfEndPercentage" class="ccf-num" aria-label="Last page ends at, percent">
                                    <span class="text-sm text-gray-500">%</span>
                                </span>
                            </div>
                            @error('pdfEndPercentage') <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                        </div>
                    </div>
                @endif
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
                    <x-file-picker model="videoFileId" kind="video" :files="$this->videoFiles" :live="true" />
                    @error('videoFileId') <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                </div>

      
            @else
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Video URL (YouTube link, MP4 link, etc.)</label>
                    <input type="url" wire:model.live.debounce.600ms="videoExternalUrl" class="w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 p-2 border" style="outline: none;" placeholder="https://www.youtube.com/watch?v=...">
                    @error('videoExternalUrl') <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                    <p class="text-xs text-gray-500 mt-1">Saves right away and plays from the link. A copy is fetched in the background and takes over once it is ready.</p>
                </div>
            @endif
            
            <div class="flex gap-4 mb-6">
                <div class="w-1/2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Start Time (Optional, e.g. 01:20)</label>
                    <input type="text" wire:model.live.debounce.600ms="videoStartTime" class="w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 p-2 border" style="outline: none;" placeholder="00:00">
                    @error('videoStartTime') <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                </div>
                <div class="w-1/2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">End Time (Optional, e.g. 05:30)</label>
                    <input type="text" wire:model.live.debounce.600ms="videoEndTime" class="w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 p-2 border" style="outline: none;" placeholder="00:00">
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
                <x-file-picker model="imageFileId" kind="image" :files="$this->imageFiles" :live="true" />
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

    <div class="flex items-center justify-between gap-3" style="flex-wrap: wrap;">
        <div>
            @if($isEditing)
                <span class="ccf-status" wire:loading.flex wire:target="autosave, done, pdfStartPage, pdfEndPage, pdfStartPercentage, pdfEndPercentage, pdfCropEnabled, pdfFileId, videoFileId, imageFileId" style="color: #6B7280;">
                    <span class="ccf-status-dot"></span> Saving…
                </span>
                <span class="ccf-status" wire:loading.remove wire:target="autosave, done, pdfStartPage, pdfEndPage, pdfStartPercentage, pdfEndPercentage, pdfCropEnabled, pdfFileId, videoFileId, imageFileId"
                      style="color: {{ $errors->any() ? '#B91C1C' : '#047857' }};">
                    <span class="ccf-status-dot"></span>
                    @if($errors->any())
                        Not saved: fix the highlighted fields
                    @elseif($savedAt)
                        Saved at {{ $savedAt }}
                    @else
                        Changes save automatically
                    @endif
                </span>
            @endif
        </div>

        <div class="flex justify-end gap-3" data-no-autosave>
            @if($isEditing)
                <button type="button" wire:click="done" class="px-4 py-2 bg-indigo-600 border border-transparent rounded-md text-sm font-medium text-white hover:bg-indigo-700 cursor-pointer border-0">Done</button>
            @else
                <button type="button" wire:click="cancel" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50 cursor-pointer" style="background: white;">Cancel</button>
                <button type="button" wire:click="save" class="px-4 py-2 bg-indigo-600 border border-transparent rounded-md text-sm font-medium text-white hover:bg-indigo-700 cursor-pointer border-0">{{ $inline ? 'Add block' : 'Save Content' }}</button>
            @endif
        </div>
    </div>
</div>

<div wire:key="ccf-preview" class="ccf-preview {{ in_array($type, ['pdf', 'video'], true) ? 'w-[50%]' : 'hidden' }}" style=" flex-shrink: 0; position: sticky; top: 24px;">
    {{-- Shown and hidden from out here: wire:ignore'd elements keep whatever class they started with. --}}
    <div wire:key="ccf-pdf-preview" class="{{ $type === 'pdf' ? '' : 'hidden' }}">
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

    <div wire:key="ccf-video-preview" class="{{ $type === 'video' ? '' : 'hidden' }}">
    <div class="p-4 bg-white shadow-md rounded-lg border border-gray-200" wire:ignore id="video-form-preview"
         x-data="{
            ...@js($this->videoPreviewState()),
            current: 0,
            duration: 0,
            // A YouTube embed reloads for a new start or end; a video element just seeks.
            get embedUrl() {
                if (!this.youtubeId) return '';
                const params = new URLSearchParams({ rel: '0' });
                if (this.start) params.set('start', this.start);
                if (this.end) params.set('end', this.end);
                return 'https://www.youtube-nocookie.com/embed/' + this.youtubeId + '?' + params;
            },
            get problem() {
                if (this.start !== null && this.end !== null && this.end <= this.start) return 'The end time has to come after the start time.';
                if (this.duration && this.start !== null && this.start >= this.duration) return 'The start time is past the end of the video.';
                return '';
            },
            clock(seconds) {
                seconds = Math.max(0, Math.floor(seconds || 0));
                const h = Math.floor(seconds / 3600), m = Math.floor(seconds % 3600 / 60), s = seconds % 60;
                const pad = n => String(n).padStart(2, '0');
                return h ? h + ':' + pad(m) + ':' + pad(s) : pad(m) + ':' + pad(s);
            },
            get range() {
                const from = this.start || 0;
                const to = this.end ?? (this.duration || null);
                if (to === null) return 'Plays from ' + this.clock(from) + ' to the end';
                return 'Plays ' + this.clock(from) + ' → ' + this.clock(to) + ' (' + this.clock(Math.max(0, to - from)) + ')';
            },
            update(state) {
                const sourceChanged = state.url !== this.url;
                Object.assign(this, state);
                if (sourceChanged) { this.duration = 0; this.current = 0; }
                else this.$nextTick(() => this.toStart());
            },
            toStart() {
                const video = this.$refs.video;
                if (video && video.readyState > 0) video.currentTime = this.start || 0;
            },
            ticked() {
                const video = this.$refs.video;
                this.current = video.currentTime;
                // Stop where students' playback will stop.
                if (this.end !== null && video.currentTime >= this.end && !video.paused) video.pause();
            },
            mark(which) {
                $wire.set(which === 'start' ? 'videoStartTime' : 'videoEndTime', this.clock(this.current));
            },
         }"
         x-on:video-preview-changed.window="update($event.detail)">
        <label class="block text-sm font-medium text-gray-700 mb-2">
            Preview <span class="font-normal text-gray-500">(how this will appear to students)</span>
        </label>

        <template x-if="!url">
            <div style="aspect-ratio: 16 / 9; display: flex; align-items: center; justify-content: center; background: #F3F4F6; border: 1px solid #E5E7EB; border-radius: 8px; color: #9CA3AF; font-size: 13px; font-weight: 500; text-align: center; padding: 16px;">
                Choose a video or paste a link to preview it here.
            </div>
        </template>

        <template x-if="url && youtubeId">
            <div style="aspect-ratio: 16 / 9; border-radius: 8px; overflow: hidden; background: #000;">
                <iframe :src="embedUrl" style="width: 100%; height: 100%; border: 0;" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen title="Video preview"></iframe>
            </div>
        </template>

        <template x-if="url && !youtubeId">
            <div>
                <video x-ref="video" :src="url" controls preload="metadata" playsinline
                       style="display: block; width: 100%; max-height: 55vh; background: #000; border-radius: 8px;"
                       x-on:loadedmetadata="duration = $event.target.duration; toStart()"
                       x-on:timeupdate="ticked()"></video>

                <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-top: 10px;">
                    <span style="font: 600 12px/1 monospace; color: #111827; background: #F3F4F6; border: 1px solid #E5E7EB; border-radius: 999px; padding: 5px 10px;" x-text="clock(current)"></span>
                    <button type="button" data-no-autosave x-on:click="mark('start')" class="ccf-mark">Set start here</button>
                    <button type="button" data-no-autosave x-on:click="mark('end')" class="ccf-mark">Set end here</button>
                    <button type="button" data-no-autosave x-on:click="toStart(); $refs.video.play()" class="ccf-mark">Play from start</button>
                </div>
            </div>
        </template>

        <p x-show="url && !problem" style="margin: 10px 0 0; font-size: 12px; color: #4B5563;" x-text="range"></p>
        <p x-show="problem" x-cloak style="margin: 10px 0 0; font-size: 12px; color: #B91C1C;" x-text="problem"></p>
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

        // Only the crop moved: adjust the drawn pages in place instead of rebuilding them,
        // so a drag or a typed percentage doesn't make the preview jump back to the top.
        let shown = null;

        function show(pdf, data) {
            const range = {
                startPage: data.startPage ? parseInt(data.startPage) : 1,
                endPage: data.endPage ? parseInt(data.endPage) : null,
                startPercent: data.startPercent,
                endPercent: data.endPercent,
                cropEditing: !!data.cropEditing,
                onCropChange: onCropDragged,
            };

            if (shown && shown.pdf === pdf && shown.startPage === range.startPage && shown.endPage === range.endPage) {
                pdfViewer().setCrop(range.startPercent, range.endPercent, range.cropEditing);
            } else {
                pdfViewer().setDocument(pdf, range);
            }

            shown = {pdf: pdf, startPage: range.startPage, endPage: range.endPage};
        }

        // A handle dragged on the preview becomes the same value the slider sets.
        function onCropDragged(which, percent) {
            $wire.set(which === 'start' ? 'pdfStartPercentage' : 'pdfEndPercentage', Math.round(percent));
        }

        function loadAndRender(data) {
            if (!data.url) {
                currentUrl = null;
                loadedPdf = null;
                shown = null;
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
                // Bounds the page inputs; not worth a request of its own.
                $wire.$set('pdfPageCount', pdf.numPages, false);
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

        loadAndRender(@js($this->pdfPreviewState()));
    })();
</script>
@endscript

</div>
</div>
