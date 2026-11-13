<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Services\GlobalDnsService;
use Illuminate\Http\Request;

class GlobalDnsController extends Controller
{
    public function fetch(GlobalDnsService $service)
    {
        return response()->json(['data' => $service->metadata()])->header('Cache-Control', 'no-store');
    }

    public function save(Request $request, GlobalDnsService $service)
    {
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:0', 'max:4294967294'],
            'dns_provider' => ['required', 'string', 'regex:/^[a-z][a-z0-9_-]{0,63}$/D'],
            'email' => ['required', 'string', 'email', 'max:254'],
            'dns_env' => ['sometimes', 'nullable', 'array', 'max:64'],
        ], [
            'dns_provider.regex' => 'DNS 提供商名称格式无效。',
            'dns_env.array' => 'DNS 环境变量必须是 KEY=VALUE 对象。',
        ]);
        return response()->json(['data' => $service->save($data)])->header('Cache-Control', 'no-store');
    }
}
