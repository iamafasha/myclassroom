<?php

use Livewire\Component;
use App\Models\Module;
use App\Models\Content;
use App\Models\ModuleContent;
use App\Models\NoteContent;

new class extends Component
{
    public $moduleId;
    public $label = '';
    public $noteText = '';
    
    public function mount($moduleId)
    {
        $this->moduleId = $moduleId;
    }

    public function save()
    {
        $this->validate([
            'label' => 'required|string|max:255',
            'noteText' => 'required|string',
        ]);

        $noteContent = new NoteContent();
        $noteContent->content = $this->noteText;
        $noteContent->save();

        $content = new Content();
        $content->contentable_id = $noteContent->id;
        $content->contentable_type = NoteContent::class;
        $content->save();

        $moduleContent = new ModuleContent();
        $moduleContent->module_id = $this->moduleId;
        $moduleContent->content_id = $content->id;
        $moduleContent->label = $this->label;
        $moduleContent->slug = \Illuminate\Support\Str::slug($this->label . '-' . time());
        $moduleContent->save();

        return redirect('/');
    }
};
?>

<div class="max-w-2xl mx-auto p-6 bg-white shadow-md rounded-lg mt-10 border border-gray-200">
    <h1 class="text-2xl font-bold mb-6 text-gray-800">Add Content to Module</h1>
    
    <div class="mb-4">
        <label class="block text-sm font-medium text-gray-700 mb-1">Content Label</label>
        <input type="text" wire:model="label" class="w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 p-2 border" style="outline: none;" placeholder="e.g. Introduction Note">
        @error('label') <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> @enderror
    </div>

    <div class="mb-6">
        <label class="block text-sm font-medium text-gray-700 mb-1">Note Details</label>
        <textarea wire:model="noteText" rows="6" class="w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 p-2 border" style="outline: none;" placeholder="Write your content here..."></textarea>
        @error('noteText') <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> @enderror
    </div>

    <div class="flex justify-end space-x-3 gap-3">
        <a href="/" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50 text-decoration-none inline-block">Cancel</a>
        <button wire:click="save" class="px-4 py-2 bg-indigo-600 border border-transparent rounded-md text-sm font-medium text-white hover:bg-indigo-700 cursor-pointer border-0">Save Content</button>
    </div>
</div>
