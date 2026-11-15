<?php

namespace App\Providers;

use App\Services\Audio\AudioNormalizer;
use App\Services\Audio\FfmpegAudioNormalizer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AudioNormalizer::class, fn () => new FfmpegAudioNormalizer(
            config('mofaregh.audio.ffmpeg'),
            config('mofaregh.audio.ffprobe'),
            (int) config('mofaregh.audio.timeout'),
        ));
    }

    public function boot(): void
    {
        JsonResource::withoutWrapping();

        Password::defaults(fn () => Password::min(10));

        // Five tries a minute per address and e-mail, so passwords cannot be guessed quickly.
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(20)->by($request->ip()),
        ]);

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(600)->by($request->user()?->id ?: $request->ip()));
    }
}
