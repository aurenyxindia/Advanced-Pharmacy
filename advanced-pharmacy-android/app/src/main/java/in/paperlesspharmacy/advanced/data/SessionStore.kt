package `in`.paperlesspharmacy.advanced.data

import android.content.Context
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey
import com.google.gson.Gson
import com.google.gson.reflect.TypeToken

class SessionStore(context: Context) {
    private val gson = Gson()
    private val prefs = EncryptedSharedPreferences.create(
        context,
        "adv_pharma_session",
        MasterKey.Builder(context).setKeyScheme(MasterKey.KeyScheme.AES256_GCM).build(),
        EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
        EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM,
    )

    var accessToken: String?
        get() = prefs.getString(KEY_ACCESS, null)
        private set(value) = prefs.edit().putString(KEY_ACCESS, value).apply()

    var refreshToken: String?
        get() = prefs.getString(KEY_REFRESH, null)
        private set(value) = prefs.edit().putString(KEY_REFRESH, value).apply()

    var user: AuthUser?
        get() {
            val raw = prefs.getString(KEY_USER, null) ?: return null
            return gson.fromJson(raw, AuthUser::class.java)
        }
        private set(value) {
            if (value == null) prefs.edit().remove(KEY_USER).apply()
            else prefs.edit().putString(KEY_USER, gson.toJson(value)).apply()
        }

    var apiBaseOverride: String?
        get() = prefs.getString(KEY_API, null)
        set(value) = prefs.edit().putString(KEY_API, value).apply()

    fun storeSession(tokens: AuthTokens) {
        storeTokens(tokens.accessToken, tokens.refreshToken, tokens.user)
        saveSubscription(tokens.needsPayment, tokens.trialDaysLeft)
    }

    fun storeTokens(access: String, refresh: String, user: AuthUser?) {
        accessToken = access
        refreshToken = refresh
        this.user = user
    }

    fun clear() {
        prefs.edit().clear().apply()
    }

    fun loadRightsCache(): List<ModuleRight> {
        val raw = prefs.getString(KEY_RIGHTS, null) ?: return emptyList()
        val type = object : TypeToken<List<ModuleRight>>() {}.type
        return gson.fromJson(raw, type)
    }

    fun saveRightsCache(rights: List<ModuleRight>, isAdmin: Boolean) {
        prefs.edit()
            .putString(KEY_RIGHTS, gson.toJson(rights))
            .putBoolean(KEY_ADMIN, isAdmin)
            .apply()
    }

    fun cachedIsAdmin(): Boolean = prefs.getBoolean(KEY_ADMIN, false)

    fun businessType(): String = prefs.getString(KEY_BIZ, "retail") ?: "retail"

    fun saveBusinessType(type: String) {
        prefs.edit().putString(KEY_BIZ, type).apply()
    }

    var needsPayment: Boolean
        get() = prefs.getBoolean(KEY_NEEDS_PAY, false)
        set(value) = prefs.edit().putBoolean(KEY_NEEDS_PAY, value).apply()

    var trialDaysLeft: Int
        get() = prefs.getInt(KEY_TRIAL_DAYS, -1)
        set(value) = prefs.edit().putInt(KEY_TRIAL_DAYS, value).apply()

    fun saveSubscription(needsPayment: Boolean, daysLeft: Int?) {
        this.needsPayment = needsPayment
        this.trialDaysLeft = daysLeft ?: -1
    }

    companion object {
        private const val KEY_ACCESS = "access"
        private const val KEY_REFRESH = "refresh"
        private const val KEY_USER = "user"
        private const val KEY_RIGHTS = "rights"
        private const val KEY_ADMIN = "is_admin"
        private const val KEY_API = "api_base"
        private const val KEY_BIZ = "business_type"
        private const val KEY_NEEDS_PAY = "needs_payment"
        private const val KEY_TRIAL_DAYS = "trial_days_left"
    }
}
