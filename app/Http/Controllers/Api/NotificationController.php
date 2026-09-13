<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bell notifikasi di pojok kanan atas dashboard.
 *
 * index() sengaja mengembalikan unread_count di dalam meta, supaya
 * frontend cukup satu request untuk isi dropdown DAN angka badge-nya.
 * Kalau nanti ada layar yang cuma butuh angkanya (mis. polling tiap
 * 30 detik tanpa membuka dropdown), pakai unreadCount().
 *
 * Semua query lewat relasi $request->user()->notifications, bukan
 * DatabaseNotification::find(). Itu yang bikin user A tidak bisa
 * menandai-baca notifikasi milik user B walaupun tahu UUID-nya.
 */
class NotificationController extends Controller
{
    /** GET /api/notifications?filter=unread */
    public function index(Request $request): JsonResponse
    {
        $query = $request->user()->notifications();

        if ($request->query('filter') === 'unread') {
            $query->whereNull('read_at');
        }

        $notifications = $query->paginate(15);

        return response()->json([
            'success' => true,
            'data' => NotificationResource::collection($notifications)
                ->response()
                ->getData(true),
            'meta' => [
                'unread_count' => $request->user()->unreadNotifications()->count(),
            ],
        ]);
    }

    /** GET /api/notifications/unread-count */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'unread_count' => $request->user()->unreadNotifications()->count(),
            ],
        ]);
    }

    /** POST /api/notifications/{id}/read */
    public function markAsRead(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->find($id);

        if ($notification === null) {
            return response()->json([
                'success' => false,
                'message' => 'Notifikasi tidak ditemukan.',
            ], 404);
        }

        // Tidak memakai markAsRead() bawaan supaya read_at lama tidak
        // ketimpa kalau endpoint ini dipanggil dua kali (UI bisa saja
        // mengirim ulang saat koneksi lambat).
        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return response()->json([
            'success' => true,
            'data' => new NotificationResource($notification),
        ]);
    }

    /** POST /api/notifications/read-all */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $affected = $request->user()
            ->unreadNotifications()
            ->update(['read_at' => now()]);

        return response()->json([
            'success' => true,
            'message' => 'Semua notifikasi ditandai sudah dibaca.',
            'data' => ['marked' => $affected],
        ]);
    }

    /** DELETE /api/notifications/{id} */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $deleted = $request->user()->notifications()->where('id', $id)->delete();

        if ($deleted === 0) {
            return response()->json([
                'success' => false,
                'message' => 'Notifikasi tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Notifikasi dihapus.',
        ]);
    }
}