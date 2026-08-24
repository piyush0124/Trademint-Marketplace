<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The signed-in user's notification inbox.
 *
 * ---------------------------------------------------------------------------
 * OWNERSHIP IS STRUCTURAL, NOT CHECKED
 * ---------------------------------------------------------------------------
 * Every query starts from `$request->user()->notifications()`, so a user can only
 * ever reach their own rows. There is no policy and no `where('user_id', ...)`
 * anywhere, because there is no code path that could return someone else's
 * notification — the relationship is the authorisation.
 *
 * That is deliberately different from, say, QuotePolicy, where a quote is
 * legitimately visible to two different parties and the rules are genuinely
 * asymmetric. Here there is exactly one owner, so an explicit check would be a
 * second thing to keep in sync with the relationship.
 */
class NotificationController extends Controller
{
    /**
     * List notifications, newest first.
     *
     * Unread are not floated to the top. Chronological order is what makes an inbox
     * legible — a user who has read the top item does not want the list to reorder
     * under them on the next poll, which is exactly what sorting by read state does.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $perPage = min((int) $request->integer('per_page', 20), 50);

        $query = $user->notifications()->latest();

        // `?filter[unread]=1` for the bell dropdown, which only ever shows unread.
        if ($request->boolean('filter.unread')) {
            $query->whereNull('read_at');
        }

        $notifications = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Notifications retrieved successfully.',
            'data' => NotificationResource::collection($notifications->items()),
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
                'unread_count' => $user->unreadNotifications()->count(),
            ],
        ]);
    }

    /**
     * Just the badge number.
     *
     * A separate endpoint from `index` because the bell polls this on an interval
     * and must not pay for a page of rows plus their JSON serialisation to render a
     * single integer. It is one indexed COUNT against
     * `notifications_inbox_index`.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Unread count retrieved successfully.',
            'data' => ['unread_count' => $request->user()->unreadNotifications()->count()],
        ]);
    }

    public function markRead(Request $request, string $notification): JsonResponse
    {
        $record = $request->user()->notifications()->whereKey($notification)->first();

        if ($record === null) {
            // 404 rather than 403: from this user's perspective the notification does
            // not exist, and saying "forbidden" would confirm that it exists for
            // somebody else.
            return response()->json([
                'success' => false,
                'message' => 'Notification not found.',
                'errors' => [],
            ], 404);
        }

        // Idempotent — re-marking an already-read notification is a no-op rather than
        // an error, because a double-tap on a list item is normal.
        $record->markAsRead();

        return response()->json([
            'success' => true,
            'message' => 'Notification marked as read.',
            'data' => new NotificationResource($record->fresh()),
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        // Single UPDATE rather than loading and saving each row. A user returning
        // after a fortnight may have hundreds, and hydrating them to set one
        // timestamp would be the slowest possible way to clear a badge.
        $count = $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json([
            'success' => true,
            'message' => 'All notifications marked as read.',
            'data' => ['marked' => $count],
        ]);
    }

    /**
     * Remove a notification from the inbox.
     *
     * A genuine delete, not a soft one. This is the user's own inbox, the row is a
     * copy of a message rather than a business record, and the underlying domain
     * event and its audit entry are untouched — so nothing about the transaction
     * history is lost by letting someone tidy up.
     */
    public function destroy(Request $request, string $notification): JsonResponse
    {
        $deleted = $request->user()->notifications()->whereKey($notification)->delete();

        if ($deleted === 0) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found.',
                'errors' => [],
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Notification removed.',
            'data' => null,
        ]);
    }
}
