<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Skylight\SyncWithSkylight;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class SkylightSyncController extends Controller
{
    /**
     * Sync shopping lists and meals with Skylight now.
     */
    public function __invoke(SyncWithSkylight $sync): JsonResponse
    {
        $outcome = $sync->attempt();

        return response()->json(['message' => $outcome->message], match (true) {
            $outcome->succeeded => Response::HTTP_OK,
            $outcome->variant === 'warning' => Response::HTTP_CONFLICT,
            default => Response::HTTP_BAD_GATEWAY,
        });
    }
}
