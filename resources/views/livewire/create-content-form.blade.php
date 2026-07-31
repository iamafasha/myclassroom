<?php

use App\Models\Module;
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

new #[Layout('layouts.app')] class extends Component
{
    public $moduleId;
    #[Url] 
    public $type = 'note';

    public $label = '';
    public $noteText = '';
    public $courseId;

    public $pdfFileId = '';
    public $pdfStartPage = '';
    public $pdfEndPage = '';
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
    
    public function mount($moduleId)
    {
        $module = Module::find($moduleId);
        $this->courseId = $module->course_id;
        $this->moduleId = $moduleId;
        $this->pdfFiles = File::where('file_type', 'pdf')->get();
        $this->videoFiles = File::whereIn('file_type', ['video', 'mp4', 'mov', 'avi'])->get();
        $this->imageFiles = File::whereIn('file_type', ['image', 'png', 'jpg', 'jpeg'])->get();
        $this->addQuestion();
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


        
        if ($this->type === 'note') {
            
            $this->validate([
                'noteText' => 'required|string',
            ]);

            $contentable = new NoteContent();
            $contentable->content = $this->noteText;
            $contentable->save();
        } elseif ($this->type === 'pdf') {
            $this->validate([
                'pdfFileId' => 'required|exists:files,id',
                'pdfStartPage' => 'nullable|string',
                'pdfEndPage' => 'nullable|string',
            ]);

            $file = File::find($this->pdfFileId);

            $contentable = new PdfNotesContent();
            $contentable->name = $this->label;
            $contentable->file_url = asset('storage/' . $file->file_path);
            $contentable->start_position = $this->pdfStartPage;
            $contentable->end_position = $this->pdfEndPage;
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

            $contentable = new VideoContent();
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

            $contentable = new LinkContent();
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

                $contentable = new ImageContent();
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

            $contentable = new QuizContent();
            $contentable->title = $this->label;
            $contentable->description = $this->quizDescription;
            $contentable->questions = $this->questions;
            $contentable->save();
        }

        $content = new Content();
        $content->contentable_id = $contentable->id;
        $content->contentable_type = get_class($contentable);
        $content->save();



        $maxOrder = ModuleContent::where('module_id', $this->moduleId)->max('sort_order') ?? 0;

        $moduleContent = new ModuleContent();
        $moduleContent->module_id = $this->moduleId;
        $moduleContent->label = $this->label;
        $moduleContent->slug = \Illuminate\Support\Str::slug($this->label . '-' . time());
        $moduleContent->sort_order = $maxOrder + 1;
        $moduleContent->is_exercise = $this->isExercise;
        $moduleContent->save();
        $moduleContent->contents()->attach($content->id);

        return redirect()->route('course.module.show', ['courseId' => $this->courseId, 'moduleId' => $this->moduleId]);
    }
    

};

?>

<div class="max-w-2xl mx-auto p-6 bg-white shadow-md rounded-lg mt-10 border border-gray-200">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Add Content to Module</h1>
        
        <select wire:model.live="type" class="border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 p-2 border" style="outline: none;">
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
                <label class="block text-sm font-medium text-gray-700 mb-1">Select PDF File</label>
                <select wire:model="pdfFileId" class="w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 p-2 border" style="outline: none;">
                    <option value="">-- Choose a PDF --</option>
                    @foreach($pdfFiles as $file)
                        <option value="{{ $file->id }}">{{ $file->name }}</option>
                    @endforeach
                </select>
                @error('pdfFileId') <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                <p class="text-xs text-gray-500 mt-1">If your PDF is not here, <a href="{{ route('files.index') }}" class="text-indigo-600 hover:underline">upload it in the File Manager</a> first.</p>
            </div>
            
            <div class="flex gap-4 mb-6">
                <div class="w-1/2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Read From Page (Optional)</label>
                    <input type="text" wire:model="pdfStartPage" class="w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 p-2 border" style="outline: none;" placeholder="e.g. 5">
                    @error('pdfStartPage') <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                </div>
                <div class="w-1/2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Read To Page (Optional)</label>
                    <input type="text" wire:model="pdfEndPage" class="w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 p-2 border" style="outline: none;" placeholder="e.g. 10">
                    @error('pdfEndPage') <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> @enderror
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
        <a href="/" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50 text-decoration-none inline-block">Cancel</a>
        <button wire:click="save" class="px-4 py-2 bg-indigo-600 border border-transparent rounded-md text-sm font-medium text-white hover:bg-indigo-700 cursor-pointer border-0">Save Content</button>
    </div>
</div>
