<?php

use Livewire\Component;

new class extends Component
{

    public $modules = [
        ['date' => '09 February', 'title' => 'Numbers Bases' ],
        ['date' => '08 February', 'title' => 'Working with Integers'],
        ['date' => '07 February', 'title' => 'Fractions, Percentages and Decimals'],
    ];

    public $contents = [
        ['name' => 'Introduction to number Bases', 'type' => 'lecture'],
        ['name' => 'Number Bases Assigment 1', 'type' => 'assigment' ],
        ['name' => 'Number Bases 2', 'type' => 'notes' ],
    ];

    public $currentCourse = 'Senoir 1 Term 1';
    public $currentTopic = 'Numbers Bases';
};
?>

<div class="main-layout">
    <div class="panel-list">
        <div class="course-selector">
            <select class="select-styled">
                <option>{{ $currentCourse }}</option>
            </select>
        </div>

        <div class="module-list" style="padding-bottom: 50px;">
            @foreach($modules as $module)
                <div class="module-card design">
                    <div class="module-header">
                      
                    </div>
                    <div class="module-body">
                        <div class="module-title">{{ $module['title'] }}</div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    <div class="panel-content  "  style="padding:2rem;width:100%">
        <div class="content-header">
            <div class="content-breadcrumb">{{ $currentCourse }}</div>
            <h1 class="content-title">{{ $currentTopic }}</h1>
        </div>
    
        <div class="contents-list">
            @foreach($contents as $content)
                <div class="content-card" style="width:100%">
                    <div class="content-info">
                        <div class="content-name">{{ $content['name'] }}</div>
                        <div class="content-details">
                            <span class="badge badge-medium">{{ $content['type'] }}</span>
                        </div>
                    </div>
                    <div class="action-area">
                            <button class="btn-solve">
                               View
                            </button>
                    </div>
                </div>
            @endforeach
        </div>
        
    </div>
</div>