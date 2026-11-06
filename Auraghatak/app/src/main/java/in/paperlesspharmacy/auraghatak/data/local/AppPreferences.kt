package in.paperlesspharmacy.auraghatak.data.local

import android.content.Context
import androidx.datastore.core.DataStore
import androidx.datastore.preferences.core.Preferences
import androidx.datastore.preferences.core.edit
import androidx.datastore.preferences.core.stringPreferencesKey
import androidx.datastore.preferences.preferencesDataStore
import com.google.gson.Gson
import com.google.gson.reflect.TypeToken
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.map

private val Context.dataStore: DataStore<Preferences> by preferencesDataStore("auraghatak")

class AppPreferences(private val context: Context) {
    private val gson = Gson()
    private val pharmaciesKey = stringPreferencesKey("pharmacies_json")
    private val ordersKey = stringPreferencesKey("orders_json")
    private val activeTokenKey = stringPreferencesKey("active_token")

    val pharmacies: Flow<List<SavedPharmacy>> = context.dataStore.data.map { prefs ->
        decodeList(prefs[pharmaciesKey], object : TypeToken<List<SavedPharmacy>>() {})
    }

    val orders: Flow<List<LocalOrder>> = context.dataStore.data.map { prefs ->
        decodeList(prefs[ordersKey], object : TypeToken<List<LocalOrder>>() {})
    }

    val activeToken: Flow<String?> = context.dataStore.data.map { it[activeTokenKey] }

    suspend fun setActiveToken(token: String?) {
        context.dataStore.edit { prefs ->
            if (token.isNullOrBlank()) prefs.remove(activeTokenKey) else prefs[activeTokenKey] = token
        }
    }

    suspend fun upsertPharmacy(pharmacy: SavedPharmacy) {
        context.dataStore.edit { prefs ->
            val current = decodeList(prefs[pharmaciesKey], object : TypeToken<List<SavedPharmacy>>() {})
            val next = (listOf(pharmacy) + current.filter { it.token != pharmacy.token }).take(20)
            prefs[pharmaciesKey] = gson.toJson(next)
            prefs[activeTokenKey] = pharmacy.token
        }
    }

    suspend fun addOrder(order: LocalOrder) {
        context.dataStore.edit { prefs ->
            val current = decodeList(prefs[ordersKey], object : TypeToken<List<LocalOrder>>() {})
            prefs[ordersKey] = gson.toJson(listOf(order) + current.take(49))
        }
    }

    private fun <T> decodeList(json: String?, type: TypeToken<List<T>>): List<T> {
        if (json.isNullOrBlank()) return emptyList()
        return runCatching { gson.fromJson<List<T>>(json, type.type) }.getOrNull() ?: emptyList()
    }
}
