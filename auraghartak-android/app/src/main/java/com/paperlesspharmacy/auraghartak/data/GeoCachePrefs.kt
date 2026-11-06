package com.paperlesspharmacy.auraghartak.data

import android.content.Context
import com.google.gson.Gson
import com.google.gson.reflect.TypeToken

class GeoCachePrefs(context: Context) {
    private val sp = context.getSharedPreferences("aura_geo_cache", Context.MODE_PRIVATE)
    private val gson = Gson()

    fun loadPins(): Map<String, Pair<Double, Double>> {
        val raw = sp.getString("pins", null) ?: return emptyMap()
        val type = object : TypeToken<Map<String, List<Double>>>() {}.type
        val map = runCatching { gson.fromJson<Map<String, List<Double>>>(raw, type) }.getOrNull() ?: return emptyMap()
        return map.mapNotNull { (pin, coords) ->
            if (coords.size >= 2) pin to (coords[0] to coords[1]) else null
        }.toMap()
    }

    fun savePins(cache: Map<String, Pair<Double, Double>>) {
        val out = cache.mapValues { listOf(it.value.first, it.value.second) }
        sp.edit().putString("pins", gson.toJson(out)).apply()
    }
}
