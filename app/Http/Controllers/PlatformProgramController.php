<?php

namespace App\Http\Controllers;

use App\Support\PlatformPrograms;
use Illuminate\Http\{JsonResponse, Request};

final class PlatformProgramController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return $this->reply(PlatformPrograms::index($request->user()));
    }

    public function taskOptions(Request $request): JsonResponse
    {
        return $this->reply(PlatformPrograms::taskOptions($request->user(), $request->query()));
    }

    public function store(Request $request): JsonResponse
    {
        return $this->reply(PlatformPrograms::save($request->user(), $request->all()), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        return $this->reply(PlatformPrograms::save($request->user(), $request->all(), $id));
    }

    private function reply(array $data, int $status = 200): JsonResponse
    {
        return $this->ok($data, $status)->header('Cache-Control', 'private, no-store');
    }
}

