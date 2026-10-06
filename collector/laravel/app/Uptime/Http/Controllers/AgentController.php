<?php

namespace Pterodactyl\Uptime\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Uptime\Models\UptimeKey;
use Pterodactyl\Uptime\Models\UptimeNode;
use Pterodactyl\Uptime\Protocol\Protocol;
use Pterodactyl\Uptime\Services\EventIngestor;
use Pterodactyl\Uptime\Services\IngestException;
use Pterodactyl\Uptime\Services\EnrollmentService;

/**
 * Agent API: no session, cookies or CSRF. Events are authenticated by their Ed25519
 * signature, enrollment by a one-time token. Bodies are small and strictly validated.
 */
class AgentController extends Controller
{
    private const MAX_BODY = 8192;

    public function enroll(Request $request, EnrollmentService $enrollment): JsonResponse
    {
        if ($error = $this->guard($request)) {
            return $error;
        }
        $token = $request->json('token');
        $publicKey = $request->json('public_key');
        if (!is_string($token) || !is_string($publicKey) || $request->json('protocol') !== Protocol::VERSION) {
            return response()->json(['error' => 'invalid_request', 'message' => 'token, public_key and protocol 1 are required.'], 422);
        }

        try {
            $key = $enrollment->enroll($token, $publicKey, (string) $request->ip());
        } catch (IngestException $e) {
            return response()->json($e->toArray(), $e->status);
        }
        /** @var UptimeNode $node */
        $node = $key->node;

        return response()->json([
            'node_id' => $node->public_id,
            'fingerprint' => $key->fingerprint,
            'head' => $node->head_hash,
            'interval_s' => $node->interval_seconds,
        ], 201);
    }

    public function events(Request $request, EventIngestor $ingestor): JsonResponse
    {
        if ($error = $this->guard($request)) {
            return $error;
        }
        $payload = $request->json('payload');
        $signature = $request->json('signature');
        if (!is_string($payload) || !is_string($signature) || strlen($payload) > 2048 || strlen($signature) > 100) {
            return response()->json(['error' => 'invalid_request', 'message' => 'payload and signature strings are required.'], 422);
        }

        try {
            $result = $ingestor->ingest($payload, $signature);
        } catch (IngestException $e) {
            return response()->json($e->toArray(), $e->status);
        }

        return response()->json([
            'status' => $result['status'],
            'event_hash' => $result['event']->event_hash,
            'seq' => $result['event']->seq,
        ], $result['status'] === 'accepted' ? 201 : 200);
    }

    /**
     * Public chain position (also visible in proofs): lets an agent continue after a
     * new enrollment or a lost state file.
     */
    public function head(Request $request, string $publicId): JsonResponse
    {
        $node = UptimeNode::query()->where('public_id', $publicId)->whereNull('archived_at')->first();
        $fingerprint = (string) $request->query('key', '');
        if (!$node || preg_match('/^[0-9a-f]{64}$/', $fingerprint) !== 1) {
            return response()->json(['error' => 'unknown_node', 'message' => 'Unknown node or key.'], 404);
        }
        $key = UptimeKey::query()->where('uptime_node_id', $node->id)->where('fingerprint', $fingerprint)->first();

        return response()->json(['head' => $node->head_hash, 'last_seq' => $key ? $key->last_seq : 0]);
    }

    private function guard(Request $request): ?JsonResponse
    {
        if (strlen((string) $request->getContent()) > self::MAX_BODY) {
            return response()->json(['error' => 'too_large', 'message' => 'Request body too large.'], 413);
        }
        if (!$request->isJson() || $request->json()->all() === []) {
            return response()->json(['error' => 'invalid_request', 'message' => 'Send a JSON object.'], 400);
        }

        return null;
    }
}
