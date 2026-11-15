<?php
use Illuminate\Support\Facades\Artisan;
use App\Models\StoredFile;
use Illuminate\Support\Facades\Storage;
Artisan::command('mosh:clean-uploads', function () {
    $count = 0;
    StoredFile::where('created_at', '<', now()->subDay())->whereDoesntHave('links')->each(function ($file) use (&$count) {
        Storage::disk('local')->delete($file->path); $file->delete(); $count++;
    });
    $this->info("Removed {$count} abandoned uploads.");
});

Artisan::command('mosh:instagram-tick', function () {
    $count = app(\App\Services\Instagram\SocialWorker::class)->tick();
    $count += app(\App\Services\Social\FacebookWorker::class)->tick();
    $this->info("Instagram queue inspected; processed {$count} item(s).");
})->purpose('Process due Instagram posts once; no automatic retry of ambiguous publishing.');
Artisan::command('mosh:instagram-work {--once}', function () {
    $this->info('MOSH Instagram worker. Keep this process running; Ctrl+C stops it.');
    do {
        try {
            $count = app(\App\Services\Instagram\SocialWorker::class)->tick();
    $count += app(\App\Services\Social\FacebookWorker::class)->tick();
            if ($count) $this->info("Processed {$count} item(s).");
        } catch (\Throwable $e) {
            // Do not print request objects, access tokens or secrets into console logs.
            $this->error('Instagram worker could not complete a cycle; check database and configuration.');
            if ($this->option('once')) return 1;
        }
        if ($this->option('once')) break;
        sleep(30);
    } while (true);
    return 0;
})->purpose('Run the Instagram publishing and scheduling worker in the foreground.');

Artisan::command('mosh:social-tick', function () {
    $n=app(\App\Services\Instagram\SocialWorker::class)->tick()+app(\App\Services\Social\FacebookWorker::class)->tick();
    $this->info("Social queue checked: {$n} item(s).");
})->purpose('Run one Instagram + Facebook outbox cycle.');
Artisan::command('mosh:social-work {--once}', function () {
    do { try { app(\App\Services\Instagram\SocialWorker::class)->tick(); app(\App\Services\Social\FacebookWorker::class)->tick(); }
    catch (\Throwable $e) { $this->error('Social cycle failed; check configuration/database. Credentials are never printed.'); if($this->option('once'))return 1; }
    if($this->option('once'))break; sleep(30); } while(true); return 0;
})->purpose('Keep Instagram + Facebook scheduling running.');
