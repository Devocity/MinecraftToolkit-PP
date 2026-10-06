<?php

declare(strict_types=1);

namespace BlueWolf\MinecraftToolkit\Jobs;

use App\Models\Server;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitLog;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitPackage;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitUpdateCheck;
use BlueWolf\MinecraftToolkit\Services\MinecraftServerFileService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CheckMinecraftPostUpdateHealthJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $serverId, public readonly int $packageId) {}

    public function handle(MinecraftServerFileService $files): void
    {
        $server = Server::find($this->serverId);
        $package = MinecraftToolkitPackage::find($this->packageId);
        if (! $server || ! $package) {
            return;
        }
        try {
            $log = $files->read($server, '/logs/latest.log', 4194304);
        } catch (\Throwable) {
            $log = '';
        }
        $name = preg_quote($package->project_name, '/');
        $failed = $log === '' || preg_match('/(?:failed to load|could not load|invalid plugin|unsupportedclassversionerror|noclassdeffounderror).*'.$name.'/is', $log) === 1;
        $correlatedCrash = Schema::hasTable('resource_alert_events') && DB::table('resource_alert_events')->where('server_id', $server->id)->where('metric', 'server_crashed')->where('triggered_at', '>=', now()->subMinutes(10))->exists();
        $failed = $failed || $correlatedCrash;
        MinecraftToolkitUpdateCheck::query()->where('package_id', $package->id)->latest('id')->first()?->update([
            'status' => $failed ? 'rollback_recommended' : 'healthy',
            'message' => $failed ? ($correlatedCrash ? trans('minecrafttoolkit::strings.messages.health_crash_detected') : trans('minecrafttoolkit::strings.messages.health_start_failed')) : trans('minecrafttoolkit::strings.messages.health_ok'),
        ]);
        MinecraftToolkitLog::query()->create([
            'server_uuid' => $server->uuid, 'user_id' => null, 'action' => 'post_update_health',
            'level' => $failed ? 'warning' : 'success',
            'message' => $failed ? trans('minecrafttoolkit::strings.messages.health_rollback_recommended') : trans('minecrafttoolkit::strings.messages.health_check_passed'),
            'context_json' => ['package_id' => $package->id],
        ]);
    }
}
