<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\File;
use Illuminate\Support\Facades\Storage;

class FileController extends Controller
{
    public function index()
    {
        $files = File::latest()->get();
        return view('files.index', compact('files'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:20480', // 20MB max
            'name' => 'nullable|string|max:255',
        ]);

        $uploadedFile = $request->file('file');
        $fileName = $request->name ?: $uploadedFile->getClientOriginalName();
        $filePath = $uploadedFile->store('uploads', 'public');
        
        $extension = strtolower($uploadedFile->getClientOriginalExtension());
        $type = 'other';
        
        if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'svg'])) {
            $type = 'image';
        } elseif ($extension === 'pdf') {
            $type = 'pdf';
        } elseif (in_array($extension, ['doc', 'docx'])) {
            $type = 'word';
        } elseif (in_array($extension, ['xls', 'xlsx'])) {
            $type = 'excel';
        } elseif (in_array($extension, ['mp4', 'mov', 'avi'])) {
            $type = 'video';
        } elseif (in_array($extension, ['mp3', 'wav'])) {
            $type = 'audio';
        }

        File::create([
            'name' => $fileName,
            'file_path' => $filePath,
            'file_type' => $type,
        ]);

        return redirect()->route('files.index')->with('success', 'File uploaded successfully.');
    }
    
    public function destroy(File $file)
    {
        if (Storage::disk('public')->exists($file->file_path)) {
            Storage::disk('public')->delete($file->file_path);
        }
        $file->delete();
        
        return redirect()->route('files.index')->with('success', 'File deleted successfully.');
    }
}
