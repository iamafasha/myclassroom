<?php

namespace Database\Seeders;

use App\Models\Course;
use App\Models\Module;
use App\Models\Content;
use App\Models\ModuleContent;
use App\Models\NoteContent;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $course = Course::create(['title' => 'Senior 1 Term 1', 'slug' => 'senior-1-term-1']);
        
        $module1 = Module::create(['course_id' => $course->id, 'title' => 'Number Bases', 'slug' => 'number-bases']);
        $module2 = Module::create(['course_id' => $course->id, 'title' => 'Working with Integers', 'slug' => 'working-with-integers']);
        
        $note1 = NoteContent::create(['content' => 'Introduction to number bases notes here.']);
        $content1 = Content::create(['contentable_type' => NoteContent::class, 'contentable_id' => $note1->id]);
        
        ModuleContent::create([
            'module_id' => $module1->id,
            'content_id' => $content1->id,
            'label' => 'Introduction to number Bases',
            'slug' => 'intro-number-bases'
        ]);

        $note2 = NoteContent::create(['content' => 'More notes on number bases.']);
        $content2 = Content::create(['contentable_type' => NoteContent::class, 'contentable_id' => $note2->id]);
        
        ModuleContent::create([
            'module_id' => $module1->id,
            'content_id' => $content2->id,
            'label' => 'Number Bases 2',
            'slug' => 'number-bases-2'
        ]);
        
        $note3 = NoteContent::create(['content' => 'Notes on integers.']);
        $content3 = Content::create(['contentable_type' => NoteContent::class, 'contentable_id' => $note3->id]);
        
        ModuleContent::create([
            'module_id' => $module2->id,
            'content_id' => $content3->id,
            'label' => 'Introduction to Integers',
            'slug' => 'intro-integers'
        ]);
    }
}
