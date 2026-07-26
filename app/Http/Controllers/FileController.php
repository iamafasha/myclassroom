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
            'file' => 'required',
            'file' => 'file|max:20480',
            'name' => 'nullable|string',
        ]);

        $file = $request->file('file');
        $originalName = $file->getClientOriginalName();
        $fileName = $request->input('name') ?? $originalName;
        $filePath = $file->store('uploads', 'public');

        $extension = strtolower($file->getClientOriginalExtension());

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
        
        return response()->json([
            'success' => true,
            'message' => 'File uploaded successfully.',
            'file' => $file,
        ]);
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
