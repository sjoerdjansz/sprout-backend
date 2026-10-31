<?php

use App\Models\Material;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Files uploaded but never sent (specs/chat-attachments.md); see Material::prunable().
Schedule::command('model:prune', ['--model' => [Material::class]])->daily();
