<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams transfers-disk objects on installs where the disk is local and
 * cannot issue presigned URLs. Only reachable through signed URLs minted by
 * ShareDeliveryService::transferDownloadUrl().
 */
class TransferDownloadController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        $path = (string) $request->query('path');
        $disk = Storage::disk('transfers');

        abort_unless($path !== '' && $disk->exists($path), 404);

        return $disk->download($path, basename($path));
    }
}
