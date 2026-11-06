package `in`.paperlesspharmacy.advanced.data.master

import android.content.Context
import com.google.gson.Gson
import com.google.gson.reflect.TypeToken

object MasterConfigLoader {
    private val gson = Gson()

    fun loadMasters(context: Context): Map<String, MasterConfigDto> {
        val json = context.assets.open("masters_config.json").bufferedReader().readText()
        val type = object : TypeToken<Map<String, MasterConfigDto>>() {}.type
        return gson.fromJson(json, type)
    }

    fun loadGstStates(context: Context): List<GstStateDto> {
        val json = context.assets.open("gst_states.json").bufferedReader().readText()
        val type = object : TypeToken<List<GstStateDto>>() {}.type
        return gson.fromJson(json, type)
    }
}
