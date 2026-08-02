<?php

namespace App\Traits;

use App\Database\HasWorkSpace;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

trait Workspaceable
{
    public static function bootWorkspaceable()
    {
        static::addGlobalScope('workspace', function (Builder $builder) { 
            if(request()->hasSession() && request()->session()->has('workspace_id')) {
                $builder->whereHas('workspaces', function ($query) {
                    $query->where('workspace_id', request()->session()->get('workspace_id'));
                });
            }
        });
    }
    /**
     * The roles that belong to the user.
     */
    public function workspaces(): MorphToMany
    {
        return $this->morphToMany(Workspace::class, 'workspaceable');
    }

    public function workspace()
    {       
        return  new HasWorkSpace($this, new Workspace());
    }


    public function addToCurrentWorkspace()
    {
        $this->workspaces()->attach(request()->session()->get('workspace_id'));
    }
}