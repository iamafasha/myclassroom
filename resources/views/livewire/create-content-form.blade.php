<?php

use Livewire\Component;
use App\Models\Module;
use App\Models\Content;
use App\Models\ModuleContent;
use App\Models\NoteContent;
use App\Models\PdfNotesContent;
use App\Models\VideoContent;
use App\Models\File;

new class extends Component
{
    public $moduleId;
    public $type = 'note';
    
    public $label = '';
    public $noteText = '';
    
    public $pdfFileId = '';
    public $pdfStartPage = '';
    public $pdfEndPage = '';
    
    public $videoFileId = '';
    public $videoStartTime = '';
    public $videoEndTime = '';
    
    public function mount($moduleId)
    {
        $this->moduleId = $moduleId;
        $this->type = request()->query('type', 'note');
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
            $this->validate([
                'videoFileId' => 'required|exists:files,id',
                'videoStartTime' => 'nullable|string',
                'videoEndTime' => 'nullable|string',
            ]);

            $file = File::find($this->videoFileId);

            $contentable = new VideoContent();
            $contentable->name = $this->label;
            $contentable->file_url = asset('storage/' . $file->file_path);
            $contentable->start_time = $this->videoStartTime;
            $contentable->end_time = $this->videoEndTime;
            $contentable->save();
        }

        $content = new Content();
        $content->contentable_id = $contentable->id;
        $content->contentable_type = get_class($contentable);
        $content->save();

        $moduleContent = new ModuleContent();
        $moduleContent->module_id = $this->moduleId;
        $moduleContent->content_id = $content->id;
        $moduleContent->label = $this->label;
        $moduleContent->slug = \Illuminate\Support\Str::slug($this->label . '-' . time());
        $moduleContent->save();

        return redirect('/');
    }
    
    public function with()
    {
        return [
            'pdfFiles' => File::where('file_type', 'pdf')->get(),
            'videoFiles' => File::whereIn('file_type', ['video', 'mp4', 'mov', 'avi'])->get()
        ];
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
        </select>
    </div>
    
    <div class="mb-4">
        <label class="block text-sm font-medium text-gray-700 mb-1">Content Label</label>
        <input type="text" wire:model="label" class="w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 p-2 border" style="outline: none;" placeholder="e.g. Introduction Note">
        @error('label') <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> @enderror
    </div>

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
    @endif

    <div class="flex justify-end space-x-3 gap-3">
        <a href="/" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50 text-decoration-none inline-block">Cancel</a>
        <button wire:click="save" class="px-4 py-2 bg-indigo-600 border border-transparent rounded-md text-sm font-medium text-white hover:bg-indigo-700 cursor-pointer border-0">Save Content</button>
    </div>
</div>
