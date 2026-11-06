package com.paperlesspharmacy.auraghartak.notify

import android.content.Context
import com.paperlesspharmacy.auraghartak.data.EngagementPrefs
import com.paperlesspharmacy.auraghartak.data.PharmaApi
import com.paperlesspharmacy.auraghartak.data.RetailApi
import com.paperlesspharmacy.auraghartak.data.SavedOrder
import com.paperlesspharmacy.auraghartak.data.TrackResult
import com.paperlesspharmacy.auraghartak.data.UserPrefs
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext

/** Polls server order status and raises customer notifications (sound + heads-up). */
object OrderStatusSync {

    @Volatile
    private var suppressNotifyUntilMs: Long = 0L

    /** Call while merging orders from the server (e.g. Orders tab refresh) to avoid race with background poll. */
    fun suppressNotificationsFor(ms: Long) {
        suppressNotifyUntilMs = System.currentTimeMillis() + ms.coerceAtLeast(0L)
    }

    fun normalizeStatus(raw: String): String {
        val norm = raw.trim()
            .lowercase()
            .replace('-', ' ')
            .replace('_', ' ')
            .replace(Regex("\\s+"), " ")
            .trim()
            .replace(' ', '_')
        return when (norm) {
            "canceled" -> "cancelled"
            else -> norm
        }
    }

    /** Higher = more final; prevents list refresh from downgrading e.g. Cancelled → Placed. */
    fun statusRank(normalized: String): Int = when (normalized) {
        "placed", "sent", "confirmed" -> 1
        "ready", "packed" -> 2
        "out_for_delivery" -> 3
        "delivered", "cancelled" -> 4
        else -> 0
    }

    /** Prefer the more advanced status label (e.g. AGT Ready vs store placed). */
    fun pickBetterStatus(current: String, candidate: String): String {
        val cur = current.trim()
        val cand = candidate.trim()
        if (cand.isBlank()) return cur
        if (cur.isBlank()) return cand
        return if (statusRank(normalizeStatus(cand)) > statusRank(normalizeStatus(cur))) cand else cur
    }

    /** Baseline for transition detection — prefer the earlier status so upgrades still alert. */
    fun pickEarlierStatus(current: String, candidate: String): String {
        val cur = current.trim()
        val cand = candidate.trim()
        if (cand.isBlank()) return cur
        if (cur.isBlank()) return cand
        return if (statusRank(normalizeStatus(cur)) <= statusRank(normalizeStatus(cand))) cur else cand
    }

    fun shouldNotifyCustomer(normalizedStatus: String): Boolean {
        return normalizedStatus in setOf(
            "ready",
            "out_for_delivery",
            "delivered",
            "cancelled",
        )
    }

    /** List refresh — merge statuses without downgrading (never marks as already-notified). */
    fun syncMergedStatusesSilent(context: Context, after: List<SavedOrder>) {
        val engagement = EngagementPrefs(context)
        after.forEach { order ->
            val incoming = normalizeStatus(order.status)
            val prev = normalizeStatus(engagement.lastKnownStatus(order.orderNo))
            if (prev.isNotBlank() && statusRank(prev) > statusRank(incoming)) {
                return@forEach
            }
            engagement.saveLastKnownStatus(order.orderNo, order.status)
        }
    }

    fun onStatusLearned(
        context: Context,
        orderNo: String,
        newStatus: String,
        previousKnown: String?,
        savedStatusHint: String? = null,
        bypassSuppress: Boolean = false,
    ) {
        val engagement = EngagementPrefs(context)
        val storedNorm = normalizeStatus(engagement.lastKnownStatus(orderNo))
        val incomingNorm = normalizeStatus(newStatus)
        if (storedNorm.isNotBlank() && statusRank(storedNorm) > statusRank(incomingNorm)) {
            return
        }
        if (!engagement.notificationsEnabled()) {
            engagement.saveLastKnownStatus(orderNo, newStatus)
            return
        }
        if (!bypassSuppress && System.currentTimeMillis() < suppressNotifyUntilMs) {
            engagement.saveLastKnownStatus(orderNo, newStatus)
            return
        }
        val newNorm = incomingNorm
        val alreadyAlerted = engagement.lastNotifiedStatusNorm(orderNo) == newNorm
        var prev = previousKnown?.trim().orEmpty()
        if (prev.isBlank()) {
            prev = savedStatusHint?.trim().orEmpty()
        }
        if (prev.isBlank()) {
            engagement.saveLastKnownStatus(orderNo, newStatus)
            return
        }
        val prevNorm = normalizeStatus(prev)
        if (
            prevNorm != newNorm &&
            statusRank(newNorm) > statusRank(prevNorm) &&
            shouldNotifyCustomer(newNorm) &&
            !alreadyAlerted
        ) {
            NotificationHelper.notifyOrderStatus(context, orderNo, newStatus)
            engagement.saveNotifiedStatusNorm(orderNo, newNorm)
        }
        engagement.saveLastKnownStatus(orderNo, newStatus)
    }

    suspend fun pollAllSavedOrders(context: Context): Int = withContext(Dispatchers.IO) {
        val engagement = EngagementPrefs(context)
        if (!engagement.notificationsEnabled()) return@withContext 0
        val prefs = UserPrefs(context)
        val orders = prefs.orders().filter {
            (it.kind == "store" && (it.trackingToken.isNotBlank() || it.orderNo.isNotBlank())) ||
                (it.kind == "agt" && it.orderNo.isNotBlank()) ||
                (it.kind == "retail" && it.orderNo.isNotBlank())
        }.filter { statusRank(normalizeStatus(it.status)) < statusRank("delivered") }
        if (orders.isEmpty()) return@withContext 0
        val api = PharmaApi()
        val retailApi = RetailApi()
        var updates = 0
        for (order in orders.take(16)) {
            runCatching {
                val status = fetchStatus(api, retailApi, order) ?: return@runCatching
                val known = engagement.lastKnownStatus(order.orderNo)
                val baseline = pickEarlierStatus(known, order.status)
                onStatusLearned(
                    context,
                    order.orderNo,
                    status,
                    baseline.takeIf { it.isNotBlank() },
                    savedStatusHint = order.status,
                )
                if (!order.status.equals(status, ignoreCase = true)) {
                    val next = prefs.orders().map {
                        if (it.orderNo == order.orderNo) it.copy(status = status) else it
                    }
                    prefs.saveOrders(next)
                    updates++
                }
            }
        }
        updates
    }

    private fun fetchStatus(
        api: PharmaApi,
        retailApi: RetailApi,
        order: SavedOrder,
    ): String? {
        return when (order.kind) {
            "store" -> {
                val track = if (order.trackingToken.isNotBlank()) {
                    api.track(token = order.trackingToken)
                } else {
                    api.track(orderNo = order.orderNo)
                }
                when (track) {
                    is TrackResult.One -> track.detail.status
                    else -> null
                }
            }
            "agt" -> {
                when (val track = api.track(orderNo = order.orderNo)) {
                    is TrackResult.One -> track.detail.status
                    else -> null
                }
            }
            "retail" -> {
                val phone = order.phone.filter { it.isDigit() }.takeLast(10)
                if (phone.length < 10) return null
                retailApi.listOrdersByPhone(phone)
                    .firstOrNull { it.orderNo == order.orderNo }
                    ?.status
            }
            else -> null
        }
    }
}
