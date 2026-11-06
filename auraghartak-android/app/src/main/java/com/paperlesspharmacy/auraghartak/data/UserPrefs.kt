package com.paperlesspharmacy.auraghartak.data

import android.content.Context
import com.google.gson.Gson
import com.google.gson.reflect.TypeToken

class UserPrefs(context: Context) {
    private val sp = context.getSharedPreferences("aura_ghartak", Context.MODE_PRIVATE)
    private val gson = Gson()

    fun profile(): Profile {
        val raw = sp.getString("profile", null) ?: return Profile()
        return runCatching { gson.fromJson(raw, Profile::class.java) }.getOrNull() ?: Profile()
    }

    fun saveProfile(profile: Profile) {
        sp.edit().putString("profile", gson.toJson(profile)).apply()
    }

    fun city(): String = sp.getString("city", "") ?: ""

    fun saveCity(city: String) {
        sp.edit().putString("city", city).apply()
    }

    /** Stable key so orders from one account are not shown after login/register as another user. */
    fun ordersOwnerKey(): String {
        val acc = account()
        when {
            acc != null && acc.id > 0L -> return "u${acc.id}"
            acc != null -> {
                val p = acc.phone.filter { it.isDigit() }.takeLast(10)
                if (p.length == 10) return "p$p"
            }
        }
        return "guest"
    }

    fun orders(): List<SavedOrder> {
        val key = ordersStorageKey()
        migrateLegacyOrdersIfNeeded(key)
        val raw = sp.getString(key, null) ?: return emptyList()
        val type = object : TypeToken<List<SavedOrder>>() {}.type
        return runCatching { gson.fromJson<List<SavedOrder>>(raw, type) }.getOrNull()
            .orEmpty()
            .map { it.copy(lines = it.lines.orEmpty()) }
    }

    fun saveOrders(orders: List<SavedOrder>) {
        val trimmed = orders.take(40)
        sp.edit().putString(ordersStorageKey(), gson.toJson(trimmed)).apply()
    }

    private fun ordersStorageKey(): String = "orders_${ordersOwnerKey()}"

    private fun migrateLegacyOrdersIfNeeded(key: String) {
        // Device guest history must never be copied onto a newly registered account.
        if (key != "orders_guest" || sp.contains(key)) return
        val legacy = sp.getString("orders", null) ?: return
        sp.edit().putString(key, legacy).remove("orders").apply()
    }

    fun authToken(): String = sp.getString("auth_token", "") ?: ""

    fun account(): AgtUserAccount? {
        val raw = sp.getString("account", null) ?: return null
        return runCatching { gson.fromJson(raw, AgtUserAccount::class.java) }.getOrNull()
    }

    fun saveSession(token: String, account: AgtUserAccount) {
        sp.edit()
            .putString("auth_token", token)
            .putString("account", gson.toJson(account))
            .putString("profile", gson.toJson(account.toProfile()))
            .apply()
    }

    fun clearSession() {
        sp.edit()
            .remove("auth_token")
            .remove("account")
            .apply()
    }
}
