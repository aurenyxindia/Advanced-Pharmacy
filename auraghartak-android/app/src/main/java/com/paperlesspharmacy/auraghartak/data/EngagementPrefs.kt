package com.paperlesspharmacy.auraghartak.data

import android.content.Context
import com.google.gson.Gson
import com.google.gson.reflect.TypeToken

data class SavedOrderLine(
    val bindingId: Long,
    val name: String,
    val qty: Int,
    val rate: Double,
)

data class FavoriteMed(
    val branchId: Long,
    val branchName: String,
    val bindingId: Long,
    val productName: String,
    val mrp: Double = 0.0,
)

data class StockAlert(
    val branchId: Long,
    val branchName: String,
    val bindingId: Long,
    val productName: String,
    val createdAt: String = "",
)

data class RefillReminder(
    val id: String,
    val medicineName: String,
    val everyDays: Int,
    val nextDue: String,
    val note: String = "",
)

class EngagementPrefs(private val appContext: Context) {
    private val sp = appContext.getSharedPreferences("aura_engagement", Context.MODE_PRIVATE)
    private val gson = Gson()

    fun notificationsEnabled(): Boolean = sp.getBoolean("notify_enabled", true)

    fun setNotificationsEnabled(on: Boolean) {
        sp.edit().putBoolean("notify_enabled", on).apply()
    }

    private fun scopedOrderKey(prefix: String, orderNo: String): String {
        val owner = UserPrefs(appContext).ordersOwnerKey()
        val ref = orderNo.trim()
        return "${prefix}_${owner}_$ref"
    }

    fun lastKnownStatus(orderNo: String): String =
        sp.getString(scopedOrderKey("status", orderNo), "") ?: ""

    fun saveLastKnownStatus(orderNo: String, status: String) {
        sp.edit().putString(scopedOrderKey("status", orderNo), status).apply()
    }

    fun lastNotifiedStatusNorm(orderNo: String): String =
        sp.getString(scopedOrderKey("notified", orderNo), "") ?: ""

    fun saveNotifiedStatusNorm(orderNo: String, normalizedStatus: String) {
        sp.edit().putString(scopedOrderKey("notified", orderNo), normalizedStatus).apply()
    }

    fun favorites(): List<FavoriteMed> = readList("favorites")

    fun saveFavorites(list: List<FavoriteMed>) = writeList("favorites", list.take(80))

    fun stockAlerts(): List<StockAlert> = readList("stock_alerts")

    fun saveStockAlerts(list: List<StockAlert>) = writeList("stock_alerts", list.take(60))

    fun refills(): List<RefillReminder> = readList("refills")

    fun saveRefills(list: List<RefillReminder>) = writeList("refills", list.take(40))

    private inline fun <reified T> readList(key: String): List<T> {
        val raw = sp.getString(key, null) ?: return emptyList()
        val type = object : TypeToken<List<T>>() {}.type
        return runCatching { gson.fromJson<List<T>>(raw, type) }.getOrNull().orEmpty()
    }

    private fun writeList(key: String, list: List<*>) {
        sp.edit().putString(key, gson.toJson(list)).apply()
    }
}
