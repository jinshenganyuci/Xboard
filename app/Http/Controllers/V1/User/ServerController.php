<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\NodeResource;
use App\Models\User;
use App\Services\ExternalSubscription\NodeProvider;
use App\Services\ServerService;
use App\Services\UserService;
use Illuminate\Http\Request;

class ServerController extends Controller
{
    public function fetch(Request $request)
    {
        $user = User::find($request->user()->id);
        $servers = [];
        $userService = new UserService();
        if ($userService->isAvailable($user)) {
            $servers = ServerService::getAvailableServers($user);
        }
        $data = NodeResource::collection($servers)->resolve($request);
        foreach (app(NodeProvider::class)->forUser($user) as $server) {
            // The user dashboard is not a subscription: expose presentation fields only.
            $data[] = [
                'id' => $server['id'],
                'type' => $server['type'],
                'version' => data_get($server, 'protocol_settings.version'),
                'name' => $server['name'],
                'rate' => null,
                'tags' => $server['tags'] ?? [],
                'is_online' => null,
                'last_check_at' => null,
                'cache_key' => 'external_' . $server['id'],
                'external' => true,
            ];
        }
        $eTag = sha1(json_encode($data));
        if (strpos($request->header('If-None-Match', ''), $eTag) !== false ) {
            return response(null,304);
        }
        return response([
            'data' => $data
        ])->header('ETag', "\"{$eTag}\"");
    }
}
