<?php

use Livewire\Component;

new class extends Component
{
    public $activeTab = 'assignment';

    public $lessons = [
        ['date' => '09 February', 'title' => 'Common Tags and Supported attributes', 'assignments' => '0/9', 'videos' => '0/1', 'type' => 'js'],
        ['date' => '08 February', 'title' => 'Introduction to Web, HTML and Commonly Used Tags-1', 'assignments' => '6/6', 'videos' => '0/1', 'type' => 'design'],
        ['date' => '08 February', 'title' => 'HTML New MCQs-1', 'assignments' => '12/12', 'videos' => null, 'type' => 'design'],
        ['date' => '07 February', 'title' => 'Orientation', 'assignments' => null, 'videos' => '0/1', 'type' => 'design'],
        ['date' => '19 February', 'title' => 'Graph Session 3', 'assignments' => '4', 'videos' => '0/1', 'type' => 'js', 'star' => true],
        ['date' => '17 February', 'title' => 'Graph Session 2', 'assignments' => '2', 'videos' => '0/1', 'type' => 'active', 'star' => true],
        ['date' => '16 February', 'title' => 'Graph Session 1', 'assignments' => '6', 'videos' => '0/1', 'type' => 'js', 'star' => true],
        ['date' => '29 July', 'title' => 'Background Properties overview', 'assignments' => null, 'videos' => '0/1', 'type' => 'design'],
    ];

    public $assignments = [
        ['name' => 'Smallest Path problem', 'difficulty' => 'MEDIUM', 'max' => 40, 'score' => 40, 'solved' => true],
        ['name' => 'Implementing Dijkstra Algorithm', 'difficulty' => 'MEDIUM', 'max' => 40, 'score' => 0, 'solved' => false],
        ['name' => 'Distance of nearest cell having 1', 'difficulty' => 'MEDIUM', 'max' => 40, 'score' => 0, 'solved' => false],
        ['name' => 'Network Travel Time', 'difficulty' => 'MEDIUM', 'max' => 40, 'score' => 0, 'solved' => false],
        ['name' => 'Path with Minimum Effort', 'difficulty' => 'MEDIUM', 'max' => 40, 'score' => 0, 'solved' => false],
        ['name' => 'Number of Enclaves', 'difficulty' => 'MEDIUM', 'max' => 40, 'score' => 0, 'solved' => false],
    ];

    public $currentCourse = 'Frontend 1: Intro to HTML & CSS - FEBRUARY';
    public $currentTopic = 'Graph Session 2';

    public function setTab($tab) {
        $this->activeTab = $tab;
    }
};
?>

<div class="main-layout">
    <div class="panel-list">
        <div class="course-selector">
            <select class="select-styled">
                <option>{{ $currentCourse }}</option>
            </select>
        </div>

        <div class="progress-container">
            <div class="progress-card">
                <div class="progress-tooltip">21%</div>
                <div class="progress-bar-wrapper">
                    <div class="progress-bar-fill"></div>
                </div>
                <div class="total-info">
                    Total Assignments Solved : 20/96 <span style="font-style: normal; color: #9CA3AF; margin-left: 5px;">ⓘ</span>
                </div>
            </div>
        </div>

        <div class="lesson-list" style="padding-bottom: 50px;">
            @foreach($lessons as $lesson)
                <div class="lesson-card {{ $lesson['type'] == 'active' ? 'active' : ($lesson['type'] == 'js' ? 'js' : 'design') }}">
                    <div class="lesson-header">
                        <div class="lesson-date">{{ $lesson['date'] }}</div>
                        <div class="lesson-meta-right">
                            @if($lesson['assignments'])
                                <div class="meta-item meta-assignments">
                                    Assignments: {{ $lesson['assignments'] }}
                                    @if(isset($lesson['star'])) <span class="star-icon">★</span> @endif
                                </div>
                            @endif
                            @if($lesson['videos'])
                                <div class="meta-item meta-videos">Videos: {{ $lesson['videos'] }}</div>
                            @endif
                        </div>
                    </div>
                    <div class="lesson-body">
                        <div class="lesson-title">{{ $lesson['title'] }}</div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    <div class="panel-content">
        <div class="content-header">
            <div class="content-breadcrumb">{{ $currentCourse }}</div>
            <h1 class="content-title">{{ $currentTopic }}</h1>
        </div>

        <div class="tabs-container">
            <div class="tab-item {{ $activeTab == 'assignment' ? 'active' : '' }}" wire:click="setTab('assignment')">Assignment</div>
            <div class="tab-item {{ $activeTab == 'lecture' ? 'active' : '' }}" wire:click="setTab('lecture')">Lecture</div>
        </div>

        @if($activeTab == 'assignment')
        <div class="assignments-list">
            @foreach($assignments as $assignment)
                <div class="assignment-card">
                    <div class="assignment-info">
                        <div class="assignment-name">{{ $assignment['name'] }}</div>
                        <div class="assignment-details">
                            <span class="badge badge-medium">⚡ {{ $assignment['difficulty'] }}</span>
                            <div class="assignment-score-item">
                                <span style="font-size: 14px;">⚙</span> Max Score: {{ $assignment['max'] }} Points
                            </div>
                            <div class="assignment-score-item">
                                <span style="font-size: 14px;">👤</span> Your Score: {{ number_format($assignment['score'], 2) }} Points
                            </div>
                        </div>
                    </div>
                    <div class="action-area">
                        @if($assignment['solved'])
                            <div class="btn-solved">Solved</div>
                        @else
                            <button class="btn-solve">
                                Start Solving <span>→</span>
                            </button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
        @else
        <div class="empty-state-text" style="color: #9CA3AF; font-size: 18px;">
            No lectures available for this session.
        </div>
        @endif
    </div>
</div>