<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class WorkspaceController extends Controller
{
    

    public function create()
    {
        return Inertia::render('Workspace/Create');
    }

    public function store(Request $request)
    {
     
        $request->validate([
            'name' => 'required'
        ]);

        if ($request->user()->ownedWorkspaces()->exists()) {
            return redirect()->route('messages')->withErrors('You can only create one workspace.');
        }

        $organization = $request->user()->ownedWorkspaces()->create([
            'name' => $request->name,
            'slug' => str()->slug($request->name).'-' . $request->user()->id,
        ]);

        $request->user()->workspaces()->attach($organization);

        //Set as current organization in sesstion
        $request->session()->put('organization_id', $organization->id); 

        setPermissionsTeamId($organization->id);

        $request->user()->assignRole('Administrator');

        return redirect()->intended('/');
    }


    public function select()
    {
        return Inertia::render('Workspace/Select');
    }

    public function storeSelection(Request $request, Workspace $workspace){
        $user = Auth::user();
        $user->unsetRelation('roles')->unsetRelation('permissions');
        $request->session()->put('workspace_id', $workspace->id); 
        setPermissionsTeamId($workspace->id);
        return redirect()->intended('/');
    }

    public function settings()
    {
        $workspace = Workspace::current()->load('metas');
        return Inertia::render('Workspace/Settings', [
            'workspace' => $workspace,
            'settings' => $workspace->getMetaKeyValues()
        ]);
    }

    public function updateSettings(Request $request)
    {
        $workspace = Workspace::current();
        
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'settings' => 'required|array'
        ]);

        $workspace->update([
            'name' => $validated['name']
        ]);

        foreach ($validated['settings'] as $key => $value) {
            $workspace->setMeta($key, $value);
        }

        return redirect()->back()->with('success', 'Settings updated successfully.');
    }

    public function aiSettings()
    {
        $workspace = Workspace::current()->load('metas');
        $settings = $workspace->getMetaKeyValues();
        
        if (isset($settings['ai_models']) && is_string($settings['ai_models'])) {
            $settings['ai_models'] = json_decode($settings['ai_models'], true) ?? [];
        }

        return Inertia::render('Workspace/AISettings', [
            'workspace' => $workspace,
            'settings' => $settings
        ]);
    }

    public function updateAiSettings(Request $request)
    {
        $workspace = Workspace::current();
        
        $validated = $request->validate([
            'settings' => 'required|array'
        ]);

        foreach ($validated['settings'] as $key => $value) {
            $workspace->setMeta($key, $value);
        }

        return redirect()->back()->with('success', 'AI settings updated successfully.');
    }

    public function checkAiConnection(Request $request)
    {
        $request->validate([
            'provider' => 'required|string',
            'api_key' => 'required|string',
            'api_base_url' => 'nullable|string',
            'model_name' => 'nullable|string',
            'anthropic_version' => 'nullable|string',
        ]);

        $provider = $request->provider;
        $apiKey = $request->api_key;
        
        try {
            if ($provider === 'openai_compatible') {
                $baseUrl = rtrim($request->api_base_url ?: 'https://api.openai.com/v1', '/');
                $response = \Illuminate\Support\Facades\Http::withToken($apiKey)->get("{$baseUrl}/models");
                
                if ($response->successful()) {
                    return response()->json(['success' => true, 'message' => 'Connection successful!']);
                }
                return response()->json(['success' => false, 'message' => 'Failed to connect. Status: ' . $response->status()], 400);
            }

            if ($provider === 'anthropic') {
                $response = \Illuminate\Support\Facades\Http::withHeaders([
                    'x-api-key' => $apiKey,
                    'anthropic-version' => $request->anthropic_version ?: '2023-06-01',
                    'content-type' => 'application/json'
                ])->post("https://api.anthropic.com/v1/messages", [
                    'model' => 'claude-3-haiku-20240307',
                    'max_tokens' => 1,
                    'messages' => [['role' => 'user', 'content' => 'test']]
                ]);

                if ($response->successful() || $response->status() === 400) {
                     if ($response->status() === 401) {
                         return response()->json(['success' => false, 'message' => 'Invalid API key'], 401);
                     }
                     return response()->json(['success' => true, 'message' => 'Connection successful!']);
                }
                return response()->json(['success' => false, 'message' => 'Failed to connect. Status: ' . $response->status()], $response->status());
            }

            if ($provider === 'gemini') {
                $response = \Illuminate\Support\Facades\Http::get("https://generativelanguage.googleapis.com/v1beta/models?key={$apiKey}");
                if ($response->successful()) {
                    return response()->json(['success' => true, 'message' => 'Connection successful!']);
                }
                return response()->json(['success' => false, 'message' => 'Failed to connect. Status: ' . $response->status()], 400);
            }
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
        
        return response()->json(['success' => false, 'message' => 'Unknown provider'], 400);
    }

}
