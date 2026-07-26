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
            'files' => 'required|array',
            'files.*' => 'required|file|max:20480',
            'name' => 'nullable|array',
            'name.*' => 'nullable|string|max:255',
        ]);

        $uploadedFiles = $request->file('files');
        $names = $request->input('name', []);

        foreach ($uploadedFiles as $index => $uploadedFile) {
            $originalName = $uploadedFile->getClientOriginalName();
            $fileName = $originalName;
            if (isset($names[$index]) && $names[$index] !== null && $names[$index] !== '') {
                $fileName = $names[$index];
            }

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
    }else{
        $type = $extension ?? 'other';
    }

    File::create([
        'name' => $fileName,
        'file_path' => $filePath,
        'file_type' => $type,
    ]);
}


        return redirect()->route('files.index')->with('success', 'Files uploaded successfully.');
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
